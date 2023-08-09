<button id="translate-button" data-product-id="{$id_product|escape:'html':'UTF-8'}">Translate Description</button>

<script>
    $('#translate-button').click(function() {

        this.disabled = true; //Disable the button
        var id_product = {$id_product}; //Get the product id
        var language_code = $('#form_switch_language').val(); //Get the selected language code

        $.post('{$controller_url}&action=translateDescription', {
            id_product: id_product,
            language_code: language_code
        }, function(response) {
            alert(response); //Display the returned data in browser
        });

    });
</script>
