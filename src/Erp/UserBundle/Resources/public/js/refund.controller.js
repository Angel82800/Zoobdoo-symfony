var RefundController = function () {
    this.$btnRefund = $('.ref-btn.refund-btn');
    this.$btnRefunded = $('.ref-btn.refunded-btn');
    this.$btnConfirm = null;
};

RefundController.prototype.applyRefund = function () {
    if (this.$btnRefund) {
        var that = this;

        this.$btnRefund.on('click', function (event) {
            var $modal = $('#erp-modal-popup'), eventRoot = event,
                    $modalFooter = $modal.find('.modal-footer');

            $modal.on('show.bs.modal', function () {
                $modalFooter.html('');
            });

            $modal.on('shown.bs.modal', function (event) {
                var eventParent = event;

                $modal.find('#btn-confirm-refund').on('click', function (event) {
                    event.preventDefault();

                    var action = this.href || this.getAttribute('href');

                    $.ajax({
                        url: action,
                        data: '',
                        dataType: 'json',
                        success: function (response) {
                            that._disableRefundButton(eventRoot.target);
                            that._processResponse(response, $modal, $modalFooter);
                        },
                        error: function (jqXHR, textStatus, errorThrown) {
                            var response;
                            try {
                                response = JSON.parse(jqXHR.responseText);
                            } catch (err) {
                                response = jqXHR.responseText;
                            }
                            that._processResponse(response, $modal, $modalFooter);
                        }
                    });
                });
            });
        });
    }
};

RefundController.prototype._disableRefundButton = function (target) {
    target.className = 'refund-btn refunded-btn disabled';
    target.innerHTML = 'Refunded';
    target.setAttribute('href', '#');
    target.removeAttribute('role');
};

RefundController.prototype._processResponse = function (response, $modal, $modalFooter) {
    var $temp = $('<div></div>').html(response.html);
    $modal.find('#body-refund').html($temp.find('#body-refund').html());
    $modalFooter.html(response.modalFooter);
};

RefundController.prototype.run = function () {
    this.applyRefund();
    
    this.$btnRefunded.on('click', function (event) {
        event.preventDefault();
    });
};

$(function () {
    var controller = new RefundController();
    controller.run();
});