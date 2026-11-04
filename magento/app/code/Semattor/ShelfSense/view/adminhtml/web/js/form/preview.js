/**
 * Live preview: the first products customers will see under "Populärt",
 * using the values on screen (before you save).
 */
define(['uiComponent', 'jquery', 'underscore', 'mage/translate'], function (Component, $, _, $t) {
    'use strict';

    return Component.extend({
        defaults: {
            previewUrl: '',
            mode: '',
            pinned: '',
            categoryId: null,
            loading: false,
            error: '',
            items: [],
            total: 0,
            notInCategory: [],
            lastUpdated: null,
            listens: {
                mode: 'schedule',
                pinned: 'schedule',
                categoryId: 'schedule'
            }
        },

        initObservable: function () {
            this._super().observe(['mode', 'pinned', 'loading', 'error', 'items', 'total', 'notInCategory', 'lastUpdated']);
            this.schedule = _.debounce(this.load.bind(this), 400);
            return this;
        },

        initialize: function () {
            this._super();
            this.schedule();
            return this;
        },

        getCategoryId: function () {
            var match;

            if (this.categoryId) {
                return this.categoryId;
            }
            match = window.location.pathname.match(/\/id\/(\d+)/);
            return match ? match[1] : null;
        },

        load: function () {
            var self = this,
                id = this.getCategoryId();

            if (!id) {
                this.error($t('Save the category first to see a preview.'));
                return;
            }
            this.loading(true);
            $.ajax({
                url: this.previewUrl,
                type: 'GET',
                dataType: 'json',
                data: {
                    category_id: id,
                    mode: this.mode() || '',
                    pinned: this.pinned() || '',
                    form_key: window.FORM_KEY
                }
            }).done(function (data) {
                self.error(data.error || '');
                self.items(data.items || []);
                self.total(data.total || 0);
                self.notInCategory(data.notInCategory || []);
                self.lastUpdated(data.lastUpdated || null);
            }).fail(function () {
                self.error($t('Could not load the preview. Try again.'));
            }).always(function () {
                self.loading(false);
            });
        },

        isActive: function () {
            return ['newest', 'smart', 'bestseller'].indexOf(this.mode()) !== -1;
        },

        heading: function () {
            return this.isActive() ?
                $t('Preview: what customers will see under "Populärt"') :
                $t('What customers see today under "Populärt"');
        },

        countText: function () {
            return $t('First %1 of %2 products').replace('%1', this.items().length).replace('%2', this.total());
        },

        updatedText: function () {
            var value = this.lastUpdated();

            if (!this.isActive()) {
                return $t('Standard: ShelfSense does not change this category.');
            }
            if (!value) {
                return $t('Not sorted yet. Click Save to apply.');
            }
            return $t('Last updated: %1').replace('%1', new Date(value.replace(' ', 'T') + 'Z').toLocaleString());
        },

        soldText: function (item) {
            return $t('%1 sold').replace('%1', item.sold);
        },

        missingText: function () {
            return $t('Not in this category (ignored): %1').replace('%1', this.notInCategory().join(', '));
        }
    });
});
