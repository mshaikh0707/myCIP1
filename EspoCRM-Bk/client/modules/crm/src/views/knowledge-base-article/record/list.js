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

Espo.define('crm:views/knowledge-base-article/record/list', 'views/record/list', function (Dep) {

    return Dep.extend({

        rowActionsView: 'crm:views/knowledge-base-article/record/row-actions/default',

        actionMoveToTop: function (data) {
            var model = this.collection.get(data.id);
            if (!model) return;

            var index = this.collection.indexOf(model);
            if (index === 0) return;

            this.ajaxPostRequest('knowledgeBaseArticle/action/moveToTop', {
                id: model.id,
                where: this.collection.getWhere()
            }).then(function () {
                this.collection.fetch();
            }.bind(this));
        },

        actionMoveUp: function (data) {
            var model = this.collection.get(data.id);
            if (!model) return;

            var index = this.collection.indexOf(model);
            if (index === 0) return;

            this.ajaxPostRequest('knowledgeBaseArticle/action/moveUp', {
                id: model.id,
                where: this.collection.getWhere()
            }).then(function () {
                this.collection.fetch();
            }.bind(this));
        },

        actionMoveDown: function (data) {
            var model = this.collection.get(data.id);
            if (!model) return;

            var index = this.collection.indexOf(model);
            if ((index === this.collection.length - 1) && (this.collection.length === this.collection.total)) return;

            this.ajaxPostRequest('knowledgeBaseArticle/action/moveDown', {
                id: model.id,
                where: this.collection.getWhere()
            }).then(function () {
                this.collection.fetch();
            }.bind(this));
        },

        actionMoveToBottom: function (data) {
            var model = this.collection.get(data.id);
            if (!model) return;

            var index = this.collection.indexOf(model);
            if ((index === this.collection.length - 1) && (this.collection.length === this.collection.total)) return;

            this.ajaxPostRequest('knowledgeBaseArticle/action/moveToBottom', {
                id: model.id,
                where: this.collection.getWhere()
            }).then(function () {
                this.collection.fetch();
            }.bind(this));
        }

    });
});

