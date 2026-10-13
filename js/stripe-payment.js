/*
 * Stripe Elements (mode JavaScript). Configuration lue dans les attributs
 * data-* du formulaire #payment-form (voir js.html.twig) : aucun script en ligne.
 * Un paiement sans redirection soumet le formulaire (action=execute_payment) ;
 * un paiement à redirection (3-D Secure) revient sur return_url, où le site
 * vérifie le paiement auprès de Stripe avant de créer la commande.
 */
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('payment-form');
    if (!form || typeof Stripe === 'undefined') {
        return;
    }

    var stripe = Stripe(form.dataset.publishableKey);
    var elements = stripe.elements({ clientSecret: form.dataset.clientSecret });
    elements.create('payment').mount('#payment-element');

    var button = document.getElementById('submit-button');
    var spinner = document.getElementById('spinner');
    var buttonText = document.getElementById('button-text');
    var message = document.getElementById('payment-message');

    function setLoading(loading) {
        button.disabled = loading;
        spinner.hidden = !loading;
        buttonText.hidden = loading;
    }

    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        setLoading(true);
        message.textContent = '';

        var result = await stripe.confirmPayment({
            elements: elements,
            confirmParams: { return_url: form.dataset.returnUrl },
            redirect: 'if_required'
        });

        if (result.error) {
            message.textContent = result.error.message;
            setLoading(false);
        } else {
            form.submit();
        }
    });
});
