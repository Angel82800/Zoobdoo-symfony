$(document).ready(function () {
    var templateIdInputAddress = '#erp_property_edit_form_address',
            templateIdInputZip = '#erp_property_edit_form_zip',
            templateIdSelect2City = '#select2-erp_property_edit_form_city_{property-id}-container'
            ;

    $(document).on('change', 'select[data-class="states"]', function () {
        var $el = $(this), $form = $el.closest('form'),
                dataId = ($form.data('id')) ? ('_' + $form.data('id')) : ''
        ;

        var $inputAddress = $(templateIdInputAddress + dataId),
                $inputZip = $(templateIdInputZip + dataId),
                $select2City = $(templateIdSelect2City.replace('_{property-id}', dataId)),
                stateCode = $el.val(),
                route = baseRoute.replace('{stateCode}', stateCode),
                $citiesEl = $('select[data-class="cities"]')
        ;

        $citiesEl.empty();
        $citiesEl.attr('disabled', 'disabled');
        $select2City.addClass('hide');
        $inputAddress.val('');
        $inputZip.val('');
        if (stateCode) {
            $.ajax({
                url: route,
                type: 'GET',
                dataType: 'json',
                success: function (response) {
                    $citiesEl.append('<option value=""></option>');
                    $.each(response, function (key, city) {
                        $citiesEl.append('<option value="' + city.id + '" data-postal-code="">' + city.name + '</option>');
                    });
                    $citiesEl.removeAttr('disabled');
                }
            });
        }
    });
    
    $(document).on('change', 'select[data-class="cities"]', function () {
        var $el = $(this), $form = $el.closest('form'),
                dataId = ($form.data('id')) ? ('_' + $form.data('id')) : ''
        ;

        var $inputAddress = $(templateIdInputAddress + dataId),
                $inputZip = $(templateIdInputZip + dataId),
                $select2City = $(templateIdSelect2City.replace('_{property-id}', dataId))
        ;

        $inputAddress.val('');
        $inputZip.val('');
        $select2City.removeClass('hide');
    });
});