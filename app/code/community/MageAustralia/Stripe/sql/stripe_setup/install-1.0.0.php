<?php
declare(strict_types=1);

/**
 * Copyright (c) 2026 Mage Australia Pty Ltd
 * All rights reserved.
 *
 * @category    MageAustralia
 * @package     MageAustralia_Stripe
 * @author      Mage Australia Pty Ltd
 * @copyright   Copyright (c) 2026 Mage Australia Pty Ltd
 * @license     http://www.opensource.org/licenses/bsd-license.php  BSD-License 2
 */

/** @var Mage_Sales_Model_Resource_Setup $installer */
$installer = $this;
$installer->startSetup();

$connection = $installer->getConnection();

// Add stripe_payment_intent_id column to sales_flat_order
$connection->addColumn(
    $installer->getTable('sales/order'),
    'stripe_payment_intent_id',
    [
        'type'     => Varien_Db_Ddl_Table::TYPE_TEXT,
        'length'   => 255,
        'nullable' => true,
        'default'  => null,
        'comment'  => 'Stripe Payment Intent ID',
    ]
);

// Add index for lookup by payment intent ID
$connection->addIndex(
    $installer->getTable('sales/order'),
    $installer->getIdxName('sales/order', ['stripe_payment_intent_id']),
    ['stripe_payment_intent_id']
);

// Create mageaustralia_stripe_transactions table
$tableName = $installer->getTable('mageaustralia_stripe_transactions');

if (!$connection->isTableExists($tableName)) {
    $table = $connection
        ->newTable($tableName)
        ->addColumn(
            'entity_id',
            Varien_Db_Ddl_Table::TYPE_INTEGER,
            null,
            [
                'identity' => true,
                'unsigned' => true,
                'nullable' => false,
                'primary'  => true,
            ],
            'Entity ID'
        )
        ->addColumn(
            'order_id',
            Varien_Db_Ddl_Table::TYPE_INTEGER,
            null,
            [
                'unsigned' => true,
                'nullable' => false,
            ],
            'Order ID'
        )
        ->addColumn(
            'payment_intent_id',
            Varien_Db_Ddl_Table::TYPE_TEXT,
            255,
            [
                'nullable' => false,
            ],
            'Stripe Payment Intent ID'
        )
        ->addColumn(
            'checkout_session_id',
            Varien_Db_Ddl_Table::TYPE_TEXT,
            255,
            [
                'nullable' => true,
            ],
            'Stripe Checkout Session ID'
        )
        ->addColumn(
            'status',
            Varien_Db_Ddl_Table::TYPE_TEXT,
            50,
            [
                'nullable' => false,
            ],
            'Transaction Status'
        )
        ->addColumn(
            'amount',
            Varien_Db_Ddl_Table::TYPE_DECIMAL,
            '12,4',
            [
                'nullable' => false,
            ],
            'Transaction Amount'
        )
        ->addColumn(
            'currency',
            Varien_Db_Ddl_Table::TYPE_TEXT,
            3,
            [
                'nullable' => false,
            ],
            'Currency Code'
        )
        ->addColumn(
            'payment_method_type',
            Varien_Db_Ddl_Table::TYPE_TEXT,
            50,
            [
                'nullable' => false,
            ],
            'Payment Method Type'
        )
        ->addColumn(
            'created_at',
            Varien_Db_Ddl_Table::TYPE_TIMESTAMP,
            null,
            [
                'nullable' => false,
                'default'  => Varien_Db_Ddl_Table::TIMESTAMP_INIT,
            ],
            'Created At'
        )
        ->addColumn(
            'updated_at',
            Varien_Db_Ddl_Table::TYPE_TIMESTAMP,
            null,
            [
                'nullable' => true,
                'default'  => null,
            ],
            'Updated At'
        )
        ->addIndex(
            $installer->getIdxName($tableName, ['order_id']),
            ['order_id']
        )
        ->addIndex(
            $installer->getIdxName($tableName, ['payment_intent_id']),
            ['payment_intent_id']
        )
        ->setComment('MageAustralia Stripe Transactions');

    $connection->createTable($table);
}

$installer->endSetup();
