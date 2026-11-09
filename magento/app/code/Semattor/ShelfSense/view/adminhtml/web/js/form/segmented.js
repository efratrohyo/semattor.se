/**
 * A select shown as a row of buttons (segmented control). Same value and saving as a select.
 */
define(['Magento_Ui/js/form/element/select'], function (Select) {
    'use strict';

    return Select.extend({
        isSelected: function (option) {
            return String(this.value() || 'default') === String(option.value);
        },

        choose: function (option) {
            this.value(option.value);
        }
    });
});
