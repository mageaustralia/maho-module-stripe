/**
 * Stripe Elements for Maho Default Checkout
 * Mounts the card element and patches review.save() for inline payment.
 */
(function() {
    "use strict";

    // Wait for the payment form to exist (loaded via AJAX)
    var attempts = 0;
    var initInterval = setInterval(function() {
        var container = document.getElementById("stripe-card-element");
        var pkEl = document.getElementById("stripe-pk");
        if (!container || !pkEl) {
            if (++attempts > 120) clearInterval(initInterval); // 60s timeout
            return;
        }
        clearInterval(initInterval);
        initStripeElements(pkEl.value, container);
    }, 500);

    function initStripeElements(publishableKey, container) {
        if (!publishableKey || !window.Stripe) return;

        var stripe = Stripe(publishableKey);
        var elements = stripe.elements();
        var cardElement = elements.create("card", {
            style: {
                base: {
                    fontSize: "16px",
                    color: "#32325d",
                    fontFamily: "-apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, sans-serif",
                    "::placeholder": { color: "#aab7c4" }
                },
                invalid: { color: "#fa755a", iconColor: "#fa755a" }
            }
        });

        var cardComplete = false;
        var stripeProcessing = false;

        cardElement.mount(container);

        cardElement.on("change", function(event) {
            var errorEl = document.getElementById("stripe-card-errors");
            if (errorEl) errorEl.textContent = event.error ? event.error.message : "";
            cardComplete = event.complete;
        });

        // Clear PI ID when switching payment methods
        if (typeof payment !== "undefined") {
            var origSwitchMethod = payment.switchMethod;
            payment.switchMethod = function(method) {
                if (method !== "stripe_card") {
                    var f = document.getElementById("stripe-payment-intent-id");
                    if (f) f.value = "";
                }
                if (origSwitchMethod) return origSwitchMethod.apply(payment, arguments);
            };
        }

        // Patch review.save() for Stripe Elements
        function patchReviewSave() {
            if (typeof review === "undefined" || !review.save) return false;
            if (review._stripePatched) return true;

            var originalSave = review.save.bind(review);
            var createPiUrl = document.getElementById("stripe-create-pi-url");
            var formKeyEl = document.getElementById("stripe-form-key");

            review.save = async function() {
                if (typeof payment === "undefined" || payment.currentMethod !== "stripe_card") {
                    return originalSave();
                }

                var piField = document.getElementById("stripe-payment-intent-id");
                if (piField && piField.value) return originalSave();

                if (!cardComplete) {
                    var errEl = document.getElementById("stripe-card-errors");
                    if (errEl) errEl.textContent = "Please complete your card details.";
                    return;
                }

                if (stripeProcessing) return;
                stripeProcessing = true;

                var btn = document.querySelector("#review-buttons-container button");
                var originalText = btn ? btn.textContent : "";
                if (btn) { btn.textContent = "Processing..."; btn.disabled = true; }

                try {
                    var piResponse = await fetch(createPiUrl ? createPiUrl.value : "", {
                        method: "POST",
                        headers: { "Content-Type": "application/x-www-form-urlencoded" },
                        body: "form_key=" + encodeURIComponent(formKeyEl ? formKeyEl.value : ""),
                    });
                    var piData = await piResponse.json();
                    if (piData.error) throw new Error(piData.message || "Could not create payment.");

                    var result = await stripe.confirmCardPayment(piData.clientSecret, {
                        payment_method: { card: cardElement }
                    });
                    if (result.error) throw new Error(result.error.message || "Payment failed.");

                    piField.value = result.paymentIntent.id;
                    stripeProcessing = false;
                    return originalSave();
                } catch (e) {
                    var errEl = document.getElementById("stripe-card-errors");
                    if (errEl) errEl.textContent = e.message || "An error occurred.";
                    if (btn) { btn.textContent = originalText; btn.disabled = false; }
                    stripeProcessing = false;
                }
            };

            review._stripePatched = true;
            return true;
        }

        if (!patchReviewSave()) {
            var pi = setInterval(function() { if (patchReviewSave()) clearInterval(pi); }, 250);
            setTimeout(function() { clearInterval(pi); }, 30000);
        }
    }
})();
