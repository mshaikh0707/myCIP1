<?php init_head(); ?>
<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-7">
                <div class="panel_s">
                    <div class="panel-body">
                        <h4 class="no-margin">
                            <?php echo $title; ?>
                        </h4>
                        <hr class="hr-panel-heading"/>
                        <?php echo form_open_multipart($this->uri->uri_string(), array('id' => 'staff_profile_table', 'autocomplete' => 'off')); ?>
                        <!-- <div class="checkbox checkbox-primary">
                         <input type="checkbox" value="1" name="two_factor_auth_enabled" id="two_factor_auth_enabled"<?php if ($current_user->two_factor_auth_enabled == 1) {
                            echo ' checked';
                        } ?>>
                         <label for="two_factor_auth_enabled"><i class="fa fa-question-circle" data-placement="right" data-toggle="tooltip" data-title="<?php echo _l('two_factor_authentication_info'); ?>"></i>
                         <?php echo _l('enable_two_factor_authentication'); ?></label>
                     </div> 
                     <hr />-->
                        <?php if ($current_user->profile_image == NULL) { ?>
                            <!--<div class="form-group">
                                <label for="profile_image"
                                       class="profile-image"><?php /*echo _l('staff_edit_profile_image'); */ ?></label>
                                <div class="input-group">
                              <span class="input-group-btn">
                                <span class="btn btn-primary"
                                      onclick="$(this).parent().find('input[type=file]').click();">Browse</span>
                                <input name="profile_image"
                                       onchange="$(this).parent().parent().find('.form-control').html($(this).val().split(/[\\|/]/).pop());"
                                       style="display: none;" type="file" id="profile_image">
                              </span>
                                    <span class="form-control"></span>
                                </div>
                            </div>-->
                        <?php } ?>
                        <?php if ($current_user->profile_image != NULL) { ?>
                            <!--<div class="form-group">
                                <div class="row">
                                    <div class="col-md-9">
                                        <?php /*echo staff_profile_image($current_user->staffid, array('img', 'staff-profile-image-thumb'));  */ ?>
                                    </div>
                                    <div class="col-md-3 text-right">
                                        <a href="<?php /*echo admin_url('staff/remove_staff_profile_image'); */ ?>"><i
                                                    class="fa fa-remove"></i></a>
                                    </div>
                                </div>
                            </div>-->
                        <?php } ?>
                        <div class="profile-pic">
                            <?php
                            $src = "";
                            if ((isset($current_user) && $current_user->profile_image != NULL)) {
                                $profileImagePath = FCPATH . 'uploads/staff_profile_images/' . $current_user->staffid . '/round_' . $current_user->profile_image;
                                if (file_exists($profileImagePath)) {
                                    $src = base_url() . 'uploads/staff_profile_images/' . $current_user->staffid . '/round_' . $current_user->profile_image;
                                }

                            } ?>
                            <div class="profile_imageview <?php echo empty($src) ? 'hidden' : ''; ?>">
                                <img src="<?php echo $src; ?>"/>
                                <?php if ($src == "") { ?>
                                    <div class="actionToEdit">
                                        <a class="clicktoaddimage" href="javascript:void(0)" onclick="croppedDelete('profile');">
                                            <span><i class="fa fa-trash"></i></span>
                                        </a>
                                        <a class="recropIcon_blk" href="javascript:void(0)" onclick="reCropp('profile');">
                                            <?php //echo _l('recrop')?>
                                            <span> <i class="fa fa-crop" aria-hidden="true"></i></span>
                                        </a>
                                    </div>

                                <?php } else { ?>
                                    <div class="actionToEdit">
                                        <a class="_delete clicktoaddimage"
                                        href="<?php echo admin_url('staff/remove_staff_profile_image'); ?>">
                                            <span><i class="fa fa-trash"></i></span>
                                        </a>
                                    </div>
                                <?php } ?>
                            </div>
                            <div class="clicktoaddimage <?php echo !empty($src) ? 'hidden' : ''; ?>">
                                <div class="drag_drop_image">
                                    <span class="icon"><i class="fa fa-image"></i></span>
                                    <span><?php echo _l('dd_upload'); ?></span>
                                </div>
                                <input id="profile_image" type="file" class="" name="profile_image"
                                       onchange="readFile(this,'profile');"/ >
                                <input type="hidden" id="imagebase64" name="imagebase64">
                            </div>
                            <div class="cropper" id="profile_croppie">
                                <div class="copper_container">
                                    <div id="profile-cropper"></div>
                                    <div class="cropper-footer">
                                        <button type="button" class="btn btn-info p9 actionDone" type="button" id=""
                                                onclick="croppedResullt('profile');">
                                            <?php echo _l('save'); ?>
                                        </button>
                                        <button type="button" class="btn btn-default actionCancel" data-dismiss="modal"
                                                onclick="croppedCancel('profile');">
                                            <?php echo _l('cancel'); ?>
                                        </button>
                                        <button type="button" class="btn btn-default actionChange"
                                                onclick="croppedChange('profile');">
                                            <?php echo _l('change'); ?>
                                        </button>

                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="firstname"
                                   class="control-label"><?php echo _l('staff_add_edit_firstname'); ?></label>
                            <input type="text" class="form-control" name="firstname" value="<?php if (isset($member)) {
                                echo $member->firstname;
                            } ?>">
                        </div>
                        <div class="form-group">
                            <label for="lastname"
                                   class="control-label"><?php echo _l('staff_add_edit_lastname'); ?></label>
                            <input type="text" class="form-control" name="lastname" value="<?php if (isset($member)) {
                                echo $member->lastname;
                            } ?>">
                        </div>
                        <div class="form-group">
                            <label for="email" class="control-label"><?php echo _l('staff_add_edit_email'); ?></label>
                            <input type="email" class="form-control" disabled="true"
                                   value="<?php echo $member->email; ?>">
                        </div>
                        <?php $value = (isset($member) ? $member->phonenumber : ''); ?>
                        <?php echo render_input('phonenumber', 'staff_add_edit_phonenumber', $value); ?>
                        <?php if (get_option('disable_language') == 0) { ?>
                            <div class="form-group">
                                <label for="default_language"
                                       class="control-label"><?php echo _l('localization_default_language'); ?></label>
                                <select name="default_language" data-live-search="true" id="default_language"
                                        class="form-control selectpicker"
                                        data-none-selected-text="<?php echo _l('dropdown_non_selected_tex'); ?>">
                                    <option value=""><?php echo _l('system_default_string'); ?></option>
                                    <?php foreach (list_folders(APPPATH . 'language') as $language) {
                                        $selected = '';
                                        if (isset($member)) {
                                            if ($member->default_language == $language) {
                                                $selected = 'selected';
                                            }
                                        }
                                        ?>
                                        <option value="<?php echo $language; ?>" <?php echo $selected; ?>><?php echo ucfirst($language); ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                        <?php } ?>
                        <!-- <div class="form-group">
                        <label for="direction"><?php echo _l('document_direction'); ?></label>
                        <select class="selectpicker" data-none-selected-text="<?php echo _l('system_default_string'); ?>" data-width="100%" name="direction" id="direction">
                          <option value="" <?php if (isset($member) && empty($member->direction)) {
                            echo 'selected';
                        } ?>></option>
                          <option value="ltr" <?php if (isset($member) && $member->direction == 'ltr') {
                            echo 'selected';
                        } ?>>LTR</option>
                          <option value="rtl" <?php if (isset($member) && $member->direction == 'rtl') {
                            echo 'selected';
                        } ?>>RTL</option>
                      </select>
                  </div> -->
                        <!-- <div class="form-group">
                    <label for="facebook" class="control-label"><i class="fa fa-facebook"></i> <?php echo _l('staff_add_edit_facebook'); ?></label>
                    <input type="text" class="form-control" name="facebook" value="<?php if (isset($member)) {
                            echo $member->facebook;
                        } ?>">
                </div>
                <div class="form-group">
                    <label for="linkedin" class="control-label"><i class="fa fa-linkedin"></i> <?php echo _l('staff_add_edit_linkedin'); ?></label>
                    <input type="text" class="form-control" name="linkedin" value="<?php if (isset($member)) {
                            echo $member->linkedin;
                        } ?>">
                </div>
                <div class="form-group">
                    <label for="skype" class="control-label"><i class="fa fa-skype"></i> <?php echo _l('staff_add_edit_skype'); ?></label>
                    <input type="text" class="form-control" name="skype" value="<?php if (isset($member)) {
                            echo $member->skype;
                        } ?>">
                </div> -->
                        <!-- <i class="fa fa-question-circle" data-toggle="tooltip" data-title="<?php echo _l('staff_email_signature_help'); ?>"></i>
                <?php $value = (isset($member) ? $member->email_signature : ''); ?>
                <?php echo render_textarea('email_signature', 'settings_email_signature', $value); ?> -->
                        <?php if (count($staff_departments) > 0) { ?>
                            <div class="form-group">
                                <label for="departments"><?php echo _l('staff_edit_profile_your_departments'); ?></label>
                                <div class="clearfix"></div>
                                <?php
                                foreach ($departments as $department) { ?>
                                    <?php
                                    foreach ($staff_departments as $staff_department) {
                                        if ($staff_department['departmentid'] == $department['departmentid']) { ?>
                                            <div class="chip-circle mtop20"><?php echo $staff_department['name']; ?></div>
                                        <?php }
                                    }

                                    ?>
                                <?php } ?>
                            </div>
                        <?php } ?>
                        <button type="submit" class="btn btn-info pull-right"><?php echo _l('submit'); ?></button>
                        <?php echo form_close(); ?>
                    </div>
                </div>
            </div>
            <div class="col-md-5">
                <div class="panel_s">

                    <div class="panel-body">
                        <h4 class="no-margin">
                            <?php echo _l('staff_edit_profile_change_your_password'); ?>
                        </h4>
                        <hr class="hr-panel-heading"/>
                        <?php echo form_open('admin/staff/change_password_profile', array('id' => 'staff_password_change_form')); ?>
                        <div class="form-group">
                            <label for="oldpassword"
                                   class="control-label"><?php echo _l('staff_edit_profile_change_old_password'); ?></label>
                            <input type="password" class="form-control" name="oldpassword" id="oldpassword">
                        </div>
                        <div class="form-group">
                            <label for="newpassword"
                                   class="control-label"><?php echo _l('staff_edit_profile_change_new_password'); ?></label>
                            <input type="password" class="form-control" id="newpassword" name="newpassword">
                        </div>
                        <div class="form-group">
                            <label for="newpasswordr"
                                   class="control-label"><?php echo _l('staff_edit_profile_change_repeat_new_password'); ?></label>
                            <input type="password" class="form-control" id="newpasswordr" name="newpasswordr">
                        </div>
                        <button type="submit" class="btn btn-info pull-right"><?php echo _l('submit'); ?></button>
                        <?php echo form_close(); ?>
                    </div>
                    <?php if ($member->last_password_change != NULL) { ?>
                        <div class="panel-footer">
                            <?php echo _l('staff_add_edit_password_last_changed'); ?>
                            : <?php echo time_ago($member->last_password_change); ?>
                        </div>
                    <?php } ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php init_tail(); ?>
<script>
    $(function () {
        $("#phonenumber").mask("(999) 999-9999", {placeholder: "(___) ___-____"});
    });

    _validate_form($('#staff_profile_table'), {firstname: 'required', lastname: 'required'});
    _validate_form($('#staff_password_change_form'), {
        oldpassword: 'required',
        newpassword: 'required',
        newpasswordr: {equalTo: "#newpassword"}
    });

</script>
</body>
</html>
