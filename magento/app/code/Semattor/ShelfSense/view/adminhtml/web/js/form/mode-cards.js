/**
 * The sort options as clickable cards instead of a dropdown.
 * Same value and saving as a normal select – only the look is different.
 */
define(['Magento_Ui/js/form/element/select'], function (Select) {
    'use strict';

    var ACTIVE = ['newest', 'smart', 'bestseller'];

    return Select.extend({
        defaults: {
            cards: {},
            hasActiveMode: false,
            listens: {
                value: 'updateActive'
            }
        },

        initObservable: function () {
            this._super().observe(['hasActiveMode']);
            this.updateActive(this.value());
            return this;
        },

        updateActive: function (value) {
            this.hasActiveMode(ACTIVE.indexOf(value) !== -1);
        },

        cardKey: function (value) {
            return value ? value : 'standard';
        },

        cardTitle: function (option) {
            var card = this.cards[this.cardKey(option.value)];
            return card ? card.title : option.label;
        },

        cardText: function (option) {
            var card = this.cards[this.cardKey(option.value)];
            return card ? card.text : '';
        },

        isSelected: function (option) {
            return (this.value() || '') === (option.value || '');
        },

        choose: function (option) {
            this.value(option.value || '');
        }
    });
});
