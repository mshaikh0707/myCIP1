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


Espo.define('crm:views/campaign/record/panels/campaign-log-records', 'views/record/panels/relationship', function (Dep) {

    return Dep.extend({

    	filterList: ["all", "sent", "opened", "optedOut", "bounced", "clicked", "leadCreated"],

    	data: function () {
    		return _.extend({
    			filterList: this.filterList,
    			filterValue: this.filterValue
    		}, Dep.prototype.data.call(this));
    	},

    	setup: function () {
            if (this.getAcl().checkScope('TargetList', 'create')) {
                this.actionList.push({
                    action: 'createTargetList',
                    label: 'Create Target List'
                });
            }
    		Dep.prototype.setup.call(this);
    	},

        actionCreateTargetList: function () {
            var attributes = {
                sourceCampaignId: this.model.id,
                sourceCampaignName: this.model.get('name')
            };

            if (!this.collection.data.primaryFilter) {
                attributes.includingActionList = [];
            } else {
                var status = Espo.Utils.upperCaseFirst(this.collection.data.primaryFilter).replace(/([A-Z])/g, ' $1');
                attributes.includingActionList = [status];
            }

            var viewName = this.getMetadata().get('clientDefs.TargetList.modalViews.edit') || 'views/modals/edit';
            this.createView('quickCreate', viewName, {
                scope: 'TargetList',
                attributes: attributes,
                fullFormDisabled: true,
                layoutName: 'createFromCampaignLog'
            }, function (view) {
                view.render();
                this.listenToOnce(view, 'after:save', function () {
                    Espo.Ui.success(this.translate('Done'));
                }, this);
            }, this);
        }

    });
});


