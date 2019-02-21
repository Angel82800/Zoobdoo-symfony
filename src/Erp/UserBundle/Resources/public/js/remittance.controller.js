var RemittanceController = function () {
    this.selectControlClass = '.select-control';
    this.selectArrowClass = '.select2-selection__arrow';
    this.formId = '#form-remittance';
    this.containerId = '#register-form-remittances';
    this.selectControl = $(this.selectControlClass);
    this.selectArrow = $(this.selectArrowClass);
    this.$modalPopup = null;
    this.$form = null;
    this.$container = null;
    this.fileStatus = null;
};

/**
 * 
 * @returns {undefined}
 */
RemittanceController.prototype.initSelect = function () {
    this.selectControl.select2();
    this.selectArrow.hide();
    $(window).resize(function () {
        this.selectControl.select2();
        this.selectArrow.hide();
    }.bind(this));
};

/**
 * 
 * @returns {undefined}
 */
RemittanceController.prototype.listenClosePopup = function () {
    var that = this;
    if (this.$modalPopup) {
        this.$modalPopup.on('hide.bs.modal', function (event) {
            that.$modalPopup.find('.modal-body').empty();
            that.$modalPopup.find('.modal-footer').empty();
        });
    }
};

/**
 * 
 * @param {Event} event
 * @returns {undefined}
 */
RemittanceController.prototype.listenSubmitForm = function (event) {
    event.preventDefault();
    this.$form.find('button[type=submit]').prop('disabled', 'disabled');
    
    var that = this;
    $.ajax({
        type: that.$form.attr('method'),
        url: that.$form.attr('action'),
        data: new FormData(that.$form.get(0)),
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function (response) {
            that._processResponse(response, that.$modalPopup.find('.modal-footer'));
        },
        error: function (jqXHR, textStatus, errorThrown) {
            var response;
            try {
                response = JSON.parse(jqXHR.responseText);
            } catch (err) {
                response = jqXHR.responseText;
            }
            that._processResponse(response, that.$modalPopup.find('.modal-footer'));
        }
    });
};

/**
 * 
 * @returns {undefined}
 */
RemittanceController.prototype.customUpload = function (inputCustom) {
    var multipleSupport = typeof $('<input/>')[0].multiple !== 'undefined',
            isIE = /msie/i.test(navigator.userAgent);

    var that = this;
    $.fn.customFile = function () {
        return this.each(function () {
            var $file = $(this).addClass('custom-file-upload-hidden'),
                    $wrap = $('<div class="file-upload-wrapper">'),
                    $button = $('<button type="button" class="btn red-btn file-upload-button">Select File</button>'),
                    $input = that.$modalPopup.find('.upload-input'),
                    $label = $('<label class="btn red-btn file-upload-button" for="' + $file[0].id + '">Select File</label>');

            $file.css({
                position: 'absolute',
                left: '-9999px'
            });

            $wrap.insertAfter($file)
                    .append($file, (isIE ? $label : $button));
            $file.attr('tabIndex', -1);
            $button.attr('tabIndex', -1);
            $button.click(function () {
                $file.focus().click();
            });

            $file.change(function () {
                var files = [],
                        fileArr, filename;

                if (multipleSupport) {
                    fileArr = $file[0].files;
                    for (var i = 0, len = fileArr.length; i < len; i++) {
                        files.push(fileArr[i].name);
                    }
                    filename = files.join(', ');
                } else {
                    filename = $file.val().split('\\').pop();
                }

                $input.val(filename).attr('title', filename);
            });

            $input.on({
                blur: function () {
                    $file.trigger('blur');
                },
                keydown: function (e) {
                    if (e.which === 13) {
                        if (!isIE) {
                            $file.trigger('click');
                        }
                    } else if (e.which === 8 || e.which === 46) {
                        $file.replaceWith($file = $file.clone(true));
                        $file.trigger('change');
                        $input.val('');
                    } else if (e.which === 9) {
                        return this;
                    } else {
                        return false;
                    }
                }
            });
        });
    };

    inputCustom.customFile();
};

/**
 * 
 * @returns {undefined}
 */
RemittanceController.prototype.run = function () {
    this.$modalPopup = $(document).find('#erp-modal-popup');

    var that = this;

    this.$modalPopup.on('shown.bs.modal', function (event) {
        that.$form = that.$modalPopup.find(that.formId);
        that.$container = that.$modalPopup.find(that.containerId);

        that.$form.unbind('submit').bind('submit', function (event) {
            that.listenSubmitForm(event);
        });

        that.$modalPopup.find(that.selectControlClass).select2({
            dropdownParent: that.$modalPopup.find('.modal-body')
        });
        that.$modalPopup.find(that.selectArrowClass).hide();

        $(window).resize(function () {
            that.$modalPopup.find(that.selectControlClass).select2({
                dropdownParent: that.$modalPopup.find('.modal-body')
            });
            that.$modalPopup.find(that.selectArrowClass).hide();
        }.bind(this));

        var $submit = that.$modalPopup.find('button[type=submit]'),
                $file = that.$modalPopup.find('._file'),
                $error = that.$modalPopup.find('#form-remittance-document-errors'),
                maxFileSize = $file.data('max-file-size')
                ;

        that.customUpload($file);

        $file.fileValidator({
            onValidation: function (files) {
                $submit.removeAttr('disabled');
                $error.html('');
            },
            onInvalid: function (validationType, file) {
                $submit.attr('disabled', 'disabled');
                $error.html($file.data('max-file-size-message'));
            },
            maxSize: maxFileSize
        });
    });

    this.initSelect();
    this.listenClosePopup();
};

/**
 * 
 * @param {String} response
 * @param {HTMLDom} $modalFooter
 * @returns {undefined}
 */
RemittanceController.prototype._processResponse = function (response, $modalFooter) {
    var $temp = $('<div></div>').html(response.html);
    this.$modalPopup.find(this.containerId).html($temp.find(this.containerId).html());
    $modalFooter.html(response.modalFooter);
};

$(function () {
    var controller = new RemittanceController();
    controller.run();
});