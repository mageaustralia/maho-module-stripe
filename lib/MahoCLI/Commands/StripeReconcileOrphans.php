<?php

/**
 * MageAustralia Stripe — orphan-payment reconciliation
 *
 * @package    MageAustralia_Stripe
 * @copyright  Copyright (c) 2026 Mage Australia Pty Ltd
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace MahoCLI\Commands;

use Mage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'stripe:reconcile-orphans',
    description: 'Find Stripe PaymentIntents with no matching Maho order and (optionally) refund/cancel them',
)]
class StripeReconcileOrphans extends BaseMahoCommand
{
    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('hours', null, InputOption::VALUE_REQUIRED, 'How far back to scan (hours)', '24')
            ->addOption('min-age', null, InputOption::VALUE_REQUIRED, 'Minimum PI age in minutes (skip in-flight orders)', '30')
            ->addOption('origin', null, InputOption::VALUE_REQUIRED, 'Only process PIs whose metadata.maho_origin matches this URL (default: ignore)', '')
            ->addOption('store', null, InputOption::VALUE_REQUIRED, 'Store ID to use for Stripe client config (default: 1)', '1')
            ->addOption('refund', null, InputOption::VALUE_NONE, 'Actually refund/cancel orphans (default: dry run)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max PIs to process (safety cap)', '500');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initMaho();

        $hours = (int) $input->getOption('hours');
        $minAgeMinutes = (int) $input->getOption('min-age');
        $originFilter = (string) $input->getOption('origin');
        $storeId = (int) $input->getOption('store');
        $apply = (bool) $input->getOption('refund');
        $limit = (int) $input->getOption('limit');

        $stripe = Mage::helper('stripe/data')->getStripeClient($storeId);
        $resource = Mage::getSingleton('core/resource');
        $read = $resource->getConnection('core_read');
        $orderTable = $resource->getTableName('sales/order');

        $now = time();
        $since = $now - ($hours * 3600);
        $cutoff = $now - ($minAgeMinutes * 60);

        $output->writeln(sprintf(
            '<info>Scanning PIs created since %s (min age %dm, %s)%s</info>',
            date('Y-m-d H:i:s', $since),
            $minAgeMinutes,
            $apply ? 'WILL refund/cancel' : 'dry run',
            $originFilter ? " — filter: origin={$originFilter}" : '',
        ));

        $intents = $stripe->paymentIntents->all([
            'created' => ['gte' => $since],
            'limit' => 100,
        ]);

        $scanned = 0;
        $skipped = 0;
        $matched = 0;
        $orphans = [];

        foreach ($intents->autoPagingIterator() as $pi) {
            if ($scanned >= $limit) {
                $output->writeln(sprintf('<comment>Limit reached at %d PIs — stopping.</comment>', $limit));
                break;
            }
            $scanned++;

            // Discriminate by metadata.maho_origin (multi-install safety)
            if ($originFilter !== '' && ($pi->metadata->maho_origin ?? '') !== $originFilter) {
                $skipped++;
                continue;
            }

            // Skip very recent PIs — they might still be in-flight
            if ($pi->created > $cutoff) {
                $skipped++;
                continue;
            }

            // We only care about PIs that represent (or could represent) actual money:
            //   - succeeded:        money settled. If no order → orphan refund candidate.
            //   - requires_capture: money authorised but not captured. If no order →
            //                       orphan cancel candidate (release the auth).
            if (!in_array($pi->status, ['succeeded', 'requires_capture'], true)) {
                $skipped++;
                continue;
            }

            // Cross-reference Maho. We persist the PI id on sales_flat_order
            // via Card.php::capture() → $order->setStripePaymentIntentId().
            $existing = $read->fetchOne(
                "SELECT entity_id FROM {$orderTable} WHERE stripe_payment_intent_id = ?",
                [$pi->id],
            );

            if ($existing) {
                $matched++;
                continue;
            }

            $orphans[] = [
                'pi' => $pi,
                'created' => date('Y-m-d H:i:s', $pi->created),
                'amount' => sprintf('%.2f %s', $pi->amount / 100, strtoupper($pi->currency)),
                'status' => $pi->status,
                'origin' => $pi->metadata->maho_origin ?? '(none)',
            ];
        }

        $output->writeln('');
        $output->writeln(sprintf(
            'Scanned %d PIs — matched %d, skipped %d, orphans %d',
            $scanned,
            $matched,
            $skipped,
            count($orphans),
        ));

        if (empty($orphans)) {
            $output->writeln('<info>No orphans found.</info>');
            return Command::SUCCESS;
        }

        $output->writeln('');
        $output->writeln('<comment>Orphan PaymentIntents:</comment>');
        foreach ($orphans as $row) {
            $output->writeln(sprintf(
                '  %s  %s  %-20s %s  origin=%s',
                $row['pi']->id,
                $row['created'],
                $row['status'],
                $row['amount'],
                $row['origin'],
            ));
        }

        if (!$apply) {
            $output->writeln('');
            $output->writeln('<comment>Dry run — pass --refund to actually refund/cancel these.</comment>');
            return Command::SUCCESS;
        }

        $output->writeln('');
        $output->writeln('<info>Refunding/cancelling orphans...</info>');
        $refunded = 0;
        $cancelled = 0;
        $failed = 0;

        foreach ($orphans as $row) {
            $pi = $row['pi'];
            try {
                if ($pi->status === 'requires_capture') {
                    $stripe->paymentIntents->cancel($pi->id);
                    $cancelled++;
                    $output->writeln("  <info>cancelled</info> {$pi->id}");
                } else {
                    $stripe->refunds->create([
                        'payment_intent' => $pi->id,
                        'reason' => 'duplicate',
                        'metadata' => [
                            'source' => 'stripe:reconcile-orphans',
                            'reason_detail' => 'no_matching_maho_order',
                        ],
                    ]);
                    $refunded++;
                    $output->writeln("  <info>refunded</info>  {$pi->id}");
                }
            } catch (\Exception $e) {
                $failed++;
                $output->writeln("  <error>failed</error>    {$pi->id}: {$e->getMessage()}");
            }
        }

        $output->writeln('');
        $output->writeln(sprintf('Done. refunded=%d cancelled=%d failed=%d', $refunded, $cancelled, $failed));
        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
