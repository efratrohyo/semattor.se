/**
 * The sort options as clickable cards instead of a dropdown.
 * Same value and saving as a normal select – only the look is different.
 */
define(['Magento_Ui/js/form/element/select', 'mage/translate'], function (Select, $t) {
    'use strict';

    var ACTIVE = ['newest', 'smart', 'bestseller', 'auto'];

    return Select.extend({
        defaults: {
            cards: {},
            hasActiveMode: false,
            autoStatus: null,
            listens: {
                value: 'updateActive'
            }
        },

        initObservable: function () {
            this._super().observe(['hasActiveMode', 'autoStatus']);
            this.updateActive(this.value());
            return this;
        },

        updateActive: function (value) {
            this.hasActiveMode(ACTIVE.indexOf(value) !== -1);
        },

        cardKey: function (value) {
            return value || 'standard';
        },

        cardTitle: function (option) {
            var card = this.cards[this.cardKey(option.value)];
            return card ? card.title : option.label;
        },

        cardText: function (option) {
            var card = this.cards[this.cardKey(option.value)];
            return card ? card.text : '';
        },

        /**
         * Auto can only be chosen when the category has enough data.
         * It stays selectable if it is already the saved option.
         */
        isLocked: function (option) {
            var status = this.autoStatus();

            if (option.value !== 'auto' || this.value() === 'auto') {
                return false;
            }
            return !status || !status.ready;
        },

        lockText: function (option) {
            var status = this.autoStatus();

            if (!this.isLocked(option)) {
                return '';
            }
            if (!status) {
                return $t('Checking data…');
            }
            return $t('Collecting data: %1 of %2 days, %3 of %4 product views.')
                .replace('%1', Math.min(status.days, status.needDays))
                .replace('%2', status.needDays)
                .replace('%3', Math.min(status.views, status.needViews).toLocaleString())
                .replace('%4', status.needViews.toLocaleString());
        },

        isSelected: function (option) {
            return this.cardKey(this.value()) === this.cardKey(option.value);
        },

        choose: function (option) {
            if (this.isLocked(option)) {
                return;
            }
            this.value(option.value);
        }
    });
});
