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

Espo.define('views/export/modals/export', ['views/modal', 'model'], function (Dep, Model) {

    return Dep.extend({

        cssName: 'export-modal',

        template: 'export/modals/export',

        data: function () {
            return {
            };
        },

        setup: function () {
            this.buttonList = [
                {
                    name: 'export',
                    label: 'Export',
                    style: 'danger'
                },
                {
                    name: 'cancel',
                    label: 'Cancel'
                }
            ];

            this.model = new Model();
            this.model.name = 'Export';

            this.scope = this.options.scope;

            if (this.options.fieldList) {
                this.model.set('fieldList', this.options.fieldList);
                this.model.set('exportAllFields', false);
            } else {
                this.model.set('exportAllFields', true);
            }
            this.model.set('format', this.getMetadata().get('app.export.formatList')[0]);

            this.createView('record', 'views/export/record/record', {
                scope: this.scope,
                model: this.model,
                el: this.getSelector() + ' .record'
            });
        },

        actionExport: function () {
            var data = this.getView('record').fetch();
            this.model.set(data);
            if (this.getView('record').validate()) return;

            var returnData = {
                exportAllFields: data.exportAllFields,
                format: data.format
            };

            if (!data.exportAllFields) {
                var attributeList = [];
                data.fieldList.forEach(function (item) {
                    if (item === 'id') {
                        attributeList.push('id');
                        return;
                    }
                    var type = this.getMetadata().get(['entityDefs', this.scope, 'fields', item, 'type']);
                    if (type) {;
                        this.getFieldManager().getAttributeList(type, item).forEach(function (attribute) {
                            attributeList.push(attribute);
                        }, this);
                    }
                    if (~item.indexOf('_')) {
                        attributeList.push(item);
                    }
                }, this);
                returnData.attributeList = attributeList;
                returnData.fieldList = data.fieldList;
            }

            this.trigger('proceed', returnData);
            this.close();
        }

    });
});

