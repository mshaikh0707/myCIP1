/************************************************************************
 * This file is part of Simply I Do.
 *
 * Simply I Do - Open Source CRM application.
 * Copyright (C) 2014-2017 Yuri Kuznetsov, Taras Machyshyn, Oleksiy Avramenko
 * Website: http://simplyido.com
 *
 * Simply I Do is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Simply I Do is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Simply I Do. If not, see http://www.gnu.org/licenses/.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "Simply I Do" word.
 ************************************************************************/

Espo.define('views/preferences/fields/dashboard-tab-list', 'views/fields/array', function (Dep) {

    return Dep.extend({

        setup: function () {
            Dep.prototype.setup.call(this);

            this.translatedOptions = {};
            var list = this.model.get(this.name) || [];
            list.forEach(function (value) {
                this.translatedOptions[value] = value;
            }, this);
        },

        getItemHtml: function (value) {
            var translatedValue = this.translatedOptions[value] || value;

            var html = '' +
            '<div class="list-group-item link-with-role form-inline" data-value="' + value + '">' +
                '<div class="pull-left" style="width: 92%; display: inline-block;">' +
                    '<input name="translatedValue" data-value="' + value + '" class="role form-control input-sm" value="'+translatedValue+'">' + 
                '</div>' +
                '<div style="width: 8%; display: inline-block; vertical-align: top;">' +
                    '<a href="javascript:" class="pull-right" data-value="' + value + '" data-action="removeValue"><span class="glyphicon glyphicon-remove"></a>' +
                '</div><br style="clear: both;" />' +
            '</div>';

            return html;
        },

        fetch: function () {
            var data = Dep.prototype.fetch.call(this);
            data.translatedOptions = {};
            (data[this.name] || []).forEach(function (value) {
                data.translatedOptions[value] = this.$el.find('input[name="translatedValue"][data-value="'+value+'"]').val() || value;
            }, this);

            return data;
        }

    });

});
