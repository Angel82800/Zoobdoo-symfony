var ListingsEditController = function () {
    this.$btnEdit = $('.btn-property-edit');
};

/**
 * 
 * @returns {undefined}
 */
ListingsEditController.prototype.ajaxifyEditForm = function () {
    if (this.$btnEdit) {
        var that = this;
        this.$btnEdit.on('click', function (event) {
            event.preventDefault();

            var $self = $(this);
            $.ajax({
                type: 'GET',
                cache: false,
                url: $self.attr('href'),
                data: '',
                async: true,
                dataType: 'json',
                success: function (response) {
                    var $form = that._getFormFromAjaxResponse(response),
                            $propDetails = that._buildPropertyDetailsForm($self, $form)
                    ;

                    that._listenSubmitOfAjaxifiedForm($self, $propDetails.find('form'));
                }
            });
        });
    }
};

/**
 * 
 * @param {type} response
 * @returns {ListingsEditController.prototype._getFormFromAjaxResponse.$form}
 */
ListingsEditController.prototype._getFormFromAjaxResponse = function (response) {
    var $tempNode = $('<div></div>').html(response.html),
            $form = $tempNode.find('form'),
            $listingForm = $form.find('.form-horizontal'),
            $saveBtn = $form.find('button[type=submit]'),
            $hiddenBlk = $form.find('div.hide')
            ;

    if ($form) {
        $form
                .html($listingForm.html())
                .children().first().append($('<center></center>').html($saveBtn))
                .append($hiddenBlk)
                .css('fontSize', '12px')
                ;
        return $form;
    } else {
        return null;
    }
};

/**
 * 
 * @param {type} $btnEdit
 * @param {type} $form
 * @returns {undefined}
 */
ListingsEditController.prototype._listenSubmitOfAjaxifiedForm = function ($btnEdit, $form) {
    var that = this;
    var callbackSuccess = function (response) {
        if (response.redirect) {
            window.location.href = response.redirect;
        } else {
            var $thisForm = that._getFormFromAjaxResponse(response),
                    $thisPropDetails = that._buildPropertyDetailsForm($btnEdit, $thisForm)
                    ;

            if ($thisPropDetails.find('form')) {
                that._listenSubmitOfAjaxifiedForm($btnEdit, $thisForm);
            }
        }
    };

    $form.submit(function (event) {
        event.preventDefault();

        $.post($form.attr('action'), $(this).serialize(), callbackSuccess, 'json');
    });
};

/**
 * 
 * @param {type} $btnEdit
 * @param {type} $form
 * @returns {ListingsEditController.prototype._buildPropertyDetailsForm.$propDetails|$|_$|Element}
 */
ListingsEditController.prototype._buildPropertyDetailsForm = function ($btnEdit, $form) {
    var $propDetails = $('#property-' + $btnEdit.data('id') + '-details');

    $propDetails
            .html($form.parent().html())
            .find('.prop-description .prop-details').css('display', 'block')
            .find('.select-container select').select2()
            ;

    return $propDetails;
};

ListingsEditController.prototype.run = function () {
    this.ajaxifyEditForm();
};

$(function () {
    var controller = new ListingsEditController();
    controller.run();
});
