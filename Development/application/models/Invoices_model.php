<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Invoices_model extends CRM_Model
{
    private $shipping_fields = array('shipping_street', 'shipping_city', 'shipping_city', 'shipping_state', 'shipping_zip', 'shipping_country');
    private $statuses = array(1, 2, 3, 4, 5, 6);

    public function __construct()
    {
        parent::__construct();
    }

    public function get_statuses()
    {
        return $this->statuses;
    }

    public function get_sale_agents()
    {
        return $this->db->query("SELECT DISTINCT(sale_agent) as sale_agent FROM tblinvoices WHERE sale_agent != 0")->result_array();
    }

    /**
     * Get invoice by id
     * @param  mixed $id
     * @return array
     */
    public function get($id = '', $where = array())
    {

        $this->db->select('*, tblcurrencies.id as currencyid, tblinvoices.id as id, tblcurrencies.name as currency_name');
        $this->db->from('tblinvoices');
        $this->db->where('tblinvoices.brandid', get_user_session());
        $this->db->join('tblcurrencies', 'tblcurrencies.id = tblinvoices.currency', 'left');
        $this->db->where($where);
        if (is_numeric($id)) {
            $this->db->where('tblinvoices' . '.id', $id);
            $invoice = $this->db->get()->row();
            if ($invoice) {
                $invoice->items = $this->get_invoice_items($id);
                $invoice->attachments = $this->get_attachments($id);

                if ($invoice->project_id != 0) {
                    $this->load->model('projects_model');
                    $invoice->project_data = $this->projects_model->get($invoice->project_id);
                } elseif ($invoice->eventid != 0) {
                    $this->load->model('projects_model');
                    $invoice->project_data = $this->projects_model->get($invoice->eventid);
                }

                $invoice->visible_attachments_to_customer_found = false;
                foreach ($invoice->attachments as $attachment) {
                    if ($attachment['visible_to_customer'] == 1) {
                        $invoice->visible_attachments_to_customer_found = true;
                        break;
                    }
                }

                $i = 0;
                $this->load->model('payments_model');
                $this->load->model('Addressbooks_model');
                $contacts = array();
                if ($invoice->leadid > 0) {
                    $this->db->select('contactid');
                    $this->db->where('leadid', $invoice->leadid);
                    $this->db->where('brandid', get_user_session());
                    /*$this->db->where('isvendor', 0);
                    $this->db->where('iscollaborator', 0);*/
                    $contacts = $this->db->get('tblleadcontact')->result();

                } elseif ($invoice->project_id > 0) {
                    $this->db->select('id');
                    $this->db->where('(parent = ' . $invoice->project_id . ' OR id = ' . $invoice->project_id . ')');
                    $this->db->where('deleted', 0);
                    $related_project_ids = $this->db->get('tblprojects')->result_array();
                    $related_project_ids = array_column($related_project_ids, 'id');
                    if (!empty($related_project_ids)) {
                        $related_project_ids = implode(",", $related_project_ids);
                        $this->db->select('contactid');
                        $this->db->distinct();
                        $this->db->where('(projectid IN (' . $related_project_ids . ') OR eventid IN (' . $related_project_ids . '))');
                        $this->db->where('isvendor', 0);
                        $this->db->where('iscollaborator', 0);
                        $this->db->where('isclient', 0);
                        $this->db->where('brandid', get_user_session());
                        $contacts = $this->db->get('tblprojectcontact')->result();
                    }
                    /*$this->db->select('contactid');
                    $this->db->where('projectid', $invoice->project_id);
                    $this->db->where('brandid', get_user_session());
                    $this->db->where('isvendor', 0);
                    $this->db->where('iscollaborator', 0);
                    $contacts = $this->db->get('tblprojectcontact')->result();*/
                }
                $clients = array();
                if (count($contacts) > 0) {
                    foreach ($contacts as $contact) {
                        $clients[] = $this->Addressbooks_model->get_contacts($contact->contactid);
                    }
                    $invoice->clients = $clients;
                }
                $invoice->client = $this->Addressbooks_model->get_contacts($invoice->clientid);
                if ($invoice->client) {
                    if ($invoice->client->company == '') {
                        $invoice->client->company = $invoice->client->firstname . ' ' . $invoice->client->lastname;
                    }
                }
                $invoice->payments = $this->payments_model->get_invoice_payments($id);
            }
            $this->db->where('invoice_id', $id);
            $invoice->pslinvoice = $this->db->get('tblproposalinvoice')->row();
            return $invoice;
        }

        $this->db->order_by('number,YEAR(date)', 'desc');

        return $this->db->get()->result_array();
    }

    /**
     * Get all invoice items
     * @param  mixed $id invoiceid
     * @return array
     */
    public function get_invoice_items($id)
    {
        $this->db->select();
        $this->db->from('tblitems_in');
        $this->db->where('rel_id', $id);
        $this->db->where('rel_type', 'invoice');
        $this->db->order_by('item_order', 'asc');
        $items = $this->db->get()->result_array();

        return $items;
    }

    public function get_invoice_item($id)
    {
        $this->db->where('id', $id);

        return $this->db->get('tblitems_in')->row();
    }

    public function mark_as_cancelled($id)
    {
        $this->db->where('id', $id);
        $this->db->update('tblinvoices', array(
            'status' => 5
        ));
        if ($this->db->affected_rows() > 0) {
            $this->log_invoice_activity($id, 'invoice_activity_marked_as_cancelled');
            do_action('invoice_marked_as_cancelled', $id);

            return true;
        }

        return false;
    }

    public function unmark_as_cancelled($id)
    {
        $this->db->where('id', $id);
        $this->db->update('tblinvoices', array(
            'status' => 1
        ));
        if ($this->db->affected_rows() > 0) {
            $this->log_invoice_activity($id, 'invoice_activity_unmarked_as_cancelled');

            return true;
        }

        return false;
    }

    /**
     * Get this invoice generated recurring invoices
     * @since  Version 1.0.1
     * @param  mixed $id main invoice id
     * @return array
     */
    public function get_invoice_recurring_invoices($id)
    {
        $this->db->where('is_recurring_from', $id);
        $invoices = $this->db->get('tblinvoices')->result_array();
        $recurring_invoices = array();
        foreach ($invoices as $invoice) {
            $recurring_invoices[] = $this->get($invoice['id']);
        }

        return $recurring_invoices;
    }

    /**
     * Get invoice total from all statuses
     * @since  Version 1.0.2
     * @param  mixed $data $_POST data
     * @return array
     */
    public function get_invoices_total($data)
    {
        $this->load->model('currencies_model');
        if (isset($data['project_id']) && $data['project_id'] != '') {
            $this->db->select('id');
            $this->db->where('(parent = ' . $data['project_id'] . ' OR id = ' . $data['project_id'] . ')');
            $this->db->where('deleted', 0);
            $related_project_ids = $this->db->get('tblprojects')->result_array();
            $related_project_ids = array_column($related_project_ids, 'id');
            $related_project_ids = implode(",", $related_project_ids);
        } else {
            $related_project_ids = "";
        }
        if (isset($data['currency'])) {
            $currencyid = $data['currency'];
        } elseif (isset($data['customer_id']) && $data['customer_id'] != '') {
            $currencyid = $this->clients_model->get_customer_default_currency($data['customer_id']);
            if ($currencyid == 0) {
                $currencyid = $this->currencies_model->get_base_currency()->id;
            }
        } elseif (isset($data['project_id']) && $data['project_id'] != '') {
            $this->load->model('projects_model');
            $currencyid = $this->projects_model->get_currency($data['project_id'])->id;
        } else {
            $currencyid = $this->currencies_model->get_base_currency()->id;
        }

        $result = array();
        $result['due'] = array();
        $result['paid'] = array();
        $result['overdue'] = array();

        $has_permission_view = has_permission('invoices', '', 'view');

        for ($i = 1; $i <= 3; $i++) {
            $this->db->select('id,total');
            $this->db->from('tblinvoices');
            //$this->db->where('currency', $currencyid);
            // Exclude cancelled invoices
            $this->db->where('status !=', 5);
            // Exclude draft
            $this->db->where('status !=', 6);
            if (isset($data['leadid'])) {
                $this->db->where('leadid', $data['leadid']);
            }
            $this->db->where('brandid', get_user_session());

            if (isset($data['project_id']) && $data['project_id'] != '') {
                if (!empty($related_project_ids)) {
                    $this->db->where('(project_id in(' . $related_project_ids . ') OR eventid in(' . $related_project_ids . '))');
                } else {
                    $this->db->where('project_id =' . $data['project_id']);
                }
            }
            if (isset($data['eventid']) && $data['eventid'] != '') {
                $this->db->where('eventid', $data['eventid']);
            }

            if ($i == 3) {
                $this->db->where('status', 4);
            }

            if (isset($data['years']) && count($data['years']) > 0) {
                $this->db->where_in('YEAR(date)', $data['years']);
            }

            if (!$has_permission_view) {
                $this->db->where('addedfrom', get_staff_user_id());
            }

            $invoices = $this->db->get()->result_array();
            // echo $this->db->last_query();exit;
            // echo "<pre>";print_r($invoices);exit;
            foreach ($invoices as $invoice) {
                if ($i == 1) {
                    $result['due'][] = get_invoice_total_left_to_pay($invoice['id'], $invoice['total']);
                } elseif ($i == 2) {
                    $paid_where = array(
                        'field' => 'amount'
                    );
                    $paid_where['where'] = array(
                        'invoiceid' => $invoice['id']
                    );
                    $result['paid'][] = sum_from_table('tblinvoicepaymentrecords', $paid_where);
                } elseif ($i == 3) {
                    $result['overdue'][] = $invoice['total'];
                }
            }
        }
        $result['due'] = array_sum($result['due']);
        $result['paid'] = array_sum($result['paid']);
        $result['overdue'] = array_sum($result['overdue']);
        $result['symbol'] = $this->currencies_model->get_currency_symbol($currencyid);
        $result['currencyid'] = $currencyid;
        return $result;
    }

    /**
     * Insert new invoice to database
     * @param array $data invoiec data
     * @return mixed - false if not insert, invoice ID if succes
     */
    public function add($data, $expense = false)
    {
        unset($data['pg']);
        $data['prefix'] = get_brand_option('invoice_prefix');
        $data['number_format'] = get_brand_option('invoice_number_format');

        if ($data['number_format'] == 4) {

            if (isset($data['leadid']) && $data['leadid'] > 0) {
                $lead_event_data = $this->leads_model->get($data['leadid']);
                $lead_event_date = date('Y-m-d', strtotime($lead_event_data->eventstartdatetime));
                $get_next_invoice = $this->invoices_model->get_next_invoice($data['leadid'], $lead_event_date);

                $data['eventno'] = $get_next_invoice['event_no'];
                $data['eventinvoiceno'] = $get_next_invoice['event_invoice_no'];
                $data['leaddate'] = $lead_event_date;
            } elseif (isset($data['project_id']) && $data['project_id'] > 0) {
                $project_event_data = $this->projects_model->get($data['project_id']);
                $project_event_date = date('Y-m-d', strtotime($project_event_data->eventstartdatetime));
                $get_next_invoice = $this->invoices_model->get_next_invoice_project($data['project_id'], $project_event_date);

                $data['eventno'] = $get_next_invoice['event_no'];
                $data['eventinvoiceno'] = $get_next_invoice['event_invoice_no'];
                $data['leaddate'] = $project_event_date;
            } elseif (isset($data['eventid']) && $data['eventid'] > 0) {
                $project_event_data = $this->projects_model->get($data['eventid']);
                $project_event_date = date('Y-m-d', strtotime($project_event_data->eventstartdatetime));
                $get_next_invoice = $this->invoices_model->get_next_invoice_project($data['eventid'], $project_event_date);

                $data['eventno'] = $get_next_invoice['event_no'];
                $data['eventinvoiceno'] = $get_next_invoice['event_invoice_no'];
                $data['leaddate'] = $project_event_date;
            }

        }
        if (isset($data['save_as_draft'])) {
            $data['status'] = 6;
            unset($data['save_as_draft']);
        }

        $saveAndSend = false;
        if (isset($data['save_and_send'])) {
            $saveAndSend = true;
            $data['status'] = 1;
            unset($data['save_and_send']);
        }

        if (isset($data['billed_tasks'])) {
            $billed_tasks = array_map("unserialize", array_unique(array_map("serialize", $data['billed_tasks'])));
            unset($data['billed_tasks']);
        }
        if (isset($data['billed_expenses'])) {
            $data['billed_expenses'] = array_map("unserialize", array_unique(array_map("serialize", $data['billed_expenses'])));
            $billed_expenses = $data['billed_expenses'];
            unset($data['billed_expenses']);
        }

        if (isset($data['project_id']) && $data['project_id'] == '' || !isset($data['project_id'])) {
            $data['project_id'] = 0;
        }

        if (isset($data['invoices_to_merge'])) {
            $invoices_to_merge = $data['invoices_to_merge'];
            unset($data['invoices_to_merge']);
        }
        if (isset($data['cancel_merged_invoices'])) {
            $cancel_merged_invoices = true;
            unset($data['cancel_merged_invoices']);
        }

        if ((isset($data['adjustment']) && !is_numeric($data['adjustment'])) || !isset($data['adjustment'])) {
            $data['adjustment'] = 0;
        } elseif (isset($data['adjustment']) && is_numeric($data['adjustment'])) {
            $data['adjustment'] = number_format($data['adjustment'], get_decimal_places(), '.', '');
        }

        $unsetters = array(
            'currency_symbol',
            'price',
            'taxname',
            'description',
            'long_description',
            'unit',
            'taxid',
            'rate',
            'quantity',
            'item_select',
            'item_group_select',
            'billed_tasks',
            'task_select',
            'task_id',
            'expense_id',
            'repeat_every_custom',
            'repeat_type_custom',
            'bill_expenses'
        );

        if (isset($data['recurring'])) {
            if ($data['recurring'] == 'custom') {
                $data['recurring_type'] = $data['repeat_type_custom'];
                $data['custom_recurring'] = 1;
                $data['recurring'] = $data['repeat_every_custom'];
            }
        } else {
            $data['custom_recurring'] = 0;
            $data['recurring'] = 0;
        }

        foreach ($unsetters as $unseter) {
            if (isset($data[$unseter])) {
                unset($data[$unseter]);
            }
        }
        if (isset($data['custom_fields'])) {
            $custom_fields = $data['custom_fields'];
            unset($data['custom_fields']);
        }
        $data['hash'] = md5(rand() . microtime());
        // Check if the key exists
        $this->db->where('hash', $data['hash']);
        $exists = $this->db->get('tblinvoices')->row();
        if ($exists) {
            $data['hash'] = md5(rand() . microtime());
        }
        if (isset($data['adminnote'])) {
            $data['adminnote'] = nl2br($data['adminnote']);
        }
        $data['clientnote'] = nl2br_save_html($data['clientnote']);
        $data['terms'] = nl2br_save_html($data['terms']);


        $data['date'] = to_sql_date($data['date']);

        if (!empty($data['duedate'])) {
            $data['duedate'] = to_sql_date($data['duedate']);
        } else {
            unset($data['duedate']);
        }
        if ($data['sale_agent'] == '') {
            $data['sale_agent'] = 0;
        }
        if (isset($data['cancel_overdue_reminders'])) {
            $data['cancel_overdue_reminders'] = 1;
        } else {
            $data['cancel_overdue_reminders'] = 0;
        }


        if (isset($data['recurring_ends_on']) && $data['recurring_ends_on'] == '') {
            unset($data['recurring_ends_on']);
        } elseif (isset($data['recurring_ends_on']) && $data['recurring_ends_on'] != '') {
            $data['recurring_ends_on'] = to_sql_date($data['recurring_ends_on']);
        }
        // Since version 1.0.1
        if (isset($data['allowed_payment_modes'])) {
            $data['allowed_payment_modes'] = serialize($data['allowed_payment_modes']);
        } else {
            $data['allowed_payment_modes'] = serialize(array());
        }
        $data['datecreated'] = date('Y-m-d H:i:s');
        if (!DEFINED('CRON')) {
            $data['addedfrom'] = get_staff_user_id();
        }
        $items = array();
        if (isset($data['newitems'])) {
            $items = $data['newitems'];
            unset($data['newitems']);
        }
        if (!isset($data['include_shipping'])) {
            foreach ($this->shipping_fields as $_s_field) {
                if (isset($data[$_s_field])) {
                    $data[$_s_field] = null;
                }
            }
            $data['show_shipping_on_invoice'] = 1;
            $data['include_shipping'] = 0;
        } else {
            // we dont need to overwrite to 1 unless its coming from the main function add
            if (!DEFINED('CRON') && $expense == false) {
                $data['include_shipping'] = 1;
                // set by default for the next time to be checked
                if (isset($data['show_shipping_on_invoice'])) {
                    $data['show_shipping_on_invoice'] = 1;
                } else {
                    $data['show_shipping_on_invoice'] = 0;
                }
            }
            // else its just like they are passed
        }
        if (isset($data['discount_total']) && $data['discount_total'] == 0) {
            $data['discount_type'] = '';
        }
        $_data = do_action('before_invoice_added', array(
            'data' => $data,
            'items' => $items
        ));
        $data = $_data['data'];
        $items = $_data['items'];

        // Added By Avni on 11/22/2017 Start
        //$data['leadid'] = $data['hdnlid'];
        unset($data['hdnlid']);
        unset($data['item_id']);

        $data['created_by'] = $this->session->userdata['staff_user_id'];
        $data['created_date'] = date('Y-m-d H:i:s');
        $data['brandid'] = get_user_session();
        // Added By Avni on 11/22/2017 End
        //echo "<pre>";print_r($items);exit;
        $this->db->insert('tblinvoices', $data);
        $insert_id = $this->db->insert_id();
        if ($insert_id) {
            // Added By Masud on 01/19/2018 Start
            $this->new_invoice_created_notification($insert_id, $data['sale_agent'], $data['clientid']);
            // Added By Masud on 01/19/2018 End
            if ($data['number_format'] != 4) {
                // Update next invoice number in settings
                $this->db->where('name', 'next_invoice_number');
                $this->db->set('value', 'value+1', false);
                $this->db->update('tblbrandsettings');
            }


            if (isset($custom_fields)) {
                handle_custom_fields_post($insert_id, $custom_fields);
            }

            if (isset($invoices_to_merge)) {
                $_merged = false;
                foreach ($invoices_to_merge as $m) {
                    $or_merge = $this->get($m);
                    if (!isset($cancel_merged_invoices)) {
                        if ($this->delete($m, true)) {
                            $_merged = true;
                        }
                    } else {
                        if ($this->mark_as_cancelled($m)) {
                            $_merged = true;
                            $admin_note = $or_merge->adminnote;
                            $note = 'Merged into invoice ' . format_invoice_number($insert_id);
                            if ($admin_note != '') {
                                $admin_note .= "\n\r" . $note;
                            } else {
                                $admin_note = $note;
                            }
                            $this->db->where('id', $m);
                            $this->db->update('tblinvoices', array(
                                'adminnote' => $admin_note
                            ));
                            // Delete the old items related from the merged invoice
                            foreach ($or_merge->items as $or_merge_item) {
                                $this->db->where('item_id', $or_merge_item['id']);
                                $this->db->delete('tblitemsrelated');
                            }
                        }
                    }
                    if ($_merged) {
                        $this->db->where('invoiceid', $or_merge->id);
                        $is_expense_invoice = $this->db->get('tblexpenses')->row();
                        if ($is_expense_invoice) {
                            $this->db->where('id', $is_expense_invoice->id);
                            $this->db->update('tblexpenses', array(
                                'invoiceid' => $insert_id
                            ));
                        }
                        if (total_rows('tblestimates', array(
                                'invoiceid' => $or_merge->id
                            )) > 0) {
                            $this->db->where('invoiceid', $or_merge->id);
                            $estimate = $this->db->get('tblestimates')->row();
                            $this->db->where('id', $estimate->id);
                            $this->db->update('tblestimates', array(
                                'invoiceid' => $insert_id
                            ));
                        } elseif (total_rows('tblproposals', array(
                                'invoice_id' => $or_merge->id
                            )) > 0) {
                            $this->db->where('invoice_id', $or_merge->id);
                            $proposal = $this->db->get('tblproposals')->row();
                            $this->db->where('id', $proposal->id);
                            $this->db->update('tblproposals', array(
                                'invoice_id' => $insert_id
                            ));
                        }
                    }
                }
            }
            if (isset($billed_tasks)) {
                foreach ($billed_tasks as $key => $tasks) {
                    foreach ($tasks as $t) {
                        $_task = $this->tasks_model->get($t);
                        $_task_data = array(
                            'billed' => 1,
                            'invoice_id' => $insert_id
                        );
                        if ($_task->status != 5) {
                            $_task_data['status'] = 5;
                            $_task_data['datefinished'] = date('Y-m-d H:i:s');
                        }
                        $this->db->where('id', $t);
                        $this->db->update('tblstafftasks', $_task_data);
                    }
                }
            }
            if (isset($billed_expenses)) {
                foreach ($billed_expenses as $key => $val) {
                    foreach ($val as $expense_id) {
                        $this->db->where('id', $expense_id);
                        $this->db->update('tblexpenses', array(
                            'invoiceid' => $insert_id
                        ));
                    }
                }
            }

            update_invoice_status($insert_id);

            if (count($items) > 0) {
                foreach ($items as $key => $item) {
                    $this->db->insert('tblitems_in', array(
                        'description' => isset($item['description']) ? $item['description'] : "",
                        'long_description' => isset($item['long_description']) ? nl2br($item['long_description']) : "",
                        'qty' => isset($item['qty']) ? $item['qty'] : "",
                        'rate' => isset($item['rate']) ? number_format($item['rate'], get_decimal_places(), '.', '') : "",
                        'amount' => isset($item['amount']) ? number_format($item['amount'], get_decimal_places(), '.', '') : "",
                        'markupdiscount' => isset($item['markupdiscount']) ? number_format($item['markupdiscount'], get_decimal_places(), '.', '') : "",
                        'rel_id' => $insert_id,
                        'rel_type' => 'invoice',
                        'item_order' => isset($item['order']) ? $item['order'] : "",
                        //'unit' => $item['unit']
                    ));

                    $itemid = $this->db->insert_id();

                    if ($itemid) {
                        if (isset($billed_tasks[$key])) {
                            foreach ($billed_tasks[$key] as $_task_id) {
                                $this->db->insert('tblitemsrelated', array(
                                    'item_id' => $itemid,
                                    'rel_id' => $_task_id,
                                    'rel_type' => 'task'
                                ));
                            }
                        } elseif (isset($billed_expenses[$key])) {
                            foreach ($billed_expenses[$key] as $_expense_id) {
                                $this->db->insert('tblitemsrelated', array(
                                    'item_id' => $itemid,
                                    'rel_id' => $_expense_id,
                                    'rel_type' => 'expense'
                                ));
                            }
                        }
                        if (isset($item['taxname']) && is_array($item['taxname'])) {
                            foreach ($item['taxname'] as $taxname) {
                                if ($taxname != '') {
                                    $tax_array = explode('|', $taxname);

                                    if (isset($tax_array[0]) && isset($tax_array[1])) {
                                        $tax_name = trim($tax_array[0]);
                                        $tax_rate = trim($tax_array[1]);
                                        if (total_rows('tblitemstax', array(
                                                'itemid' => $itemid,
                                                'taxrate' => $tax_rate,
                                                'taxname' => $tax_name,
                                                'rel_id' => $insert_id,
                                                'rel_type' => 'invoice')) == 0) {
                                            $this->db->insert('tblitemstax', array(
                                                'itemid' => $itemid,
                                                'taxrate' => $tax_rate,
                                                'taxname' => $tax_name,
                                                'rel_id' => $insert_id,
                                                'rel_type' => 'invoice'
                                            ));
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }

            $this->update_total_tax($insert_id);

            if (!DEFINED('CRON') && $expense == false) {
                $lang_key = 'invoice_activity_created';
            } elseif (!DEFINED('CRON') && $expense == true) {
                $lang_key = 'invoice_activity_from_expense';
            } elseif (DEFINED('CRON') && $expense == false) {
                $lang_key = 'invoice_activity_recurring_created';
            } else {
                $lang_key = 'invoice_activity_recurring_from_expense_created';
            }
            $this->log_invoice_activity($insert_id, $lang_key);

            if ($saveAndSend === true) {
                $this->send_invoice_to_client($insert_id, '', true, '', true);
            }
            do_action('after_invoice_added', $insert_id);

            return $insert_id;
        }

        return false;
    }

    //Added By Masud on 01/19/2018 Start

    public function new_invoice_created_notification($invoice_id, $tocontacts, $tousers, $integration = false)
    {
        $invoice_number = format_invoice_number($invoice_id);
        $notification_data = array(
            'description' => ($integration == false) ? 'not_new_invoice_created' : 'not_new_invoice_created',
            'touserid' => $tousers,
            'tocontactid' => $tocontacts,
            'eid' => $invoice_id,
            'brandid' => get_user_session(),
            'not_type' => "Invoices",
            'link' => 'invoices/invoice/' . $invoice_id,
            'additional_data' => ($integration == false ? serialize(array(
                $invoice_number
            )) : serialize(array()))
        );
        if (add_notification($notification_data)) {
            pusher_trigger_notification(array($tousers));
        }

    }

    public function invoice_update_notification($invoice_id, $tocontacts, $tousers, $integration = false)
    {
        $invoice_number = format_invoice_number($invoice_id);
        $notification_data = array(
            'description' => ($integration == false) ? 'not_invoice_updated' : 'not_invoice_updated',
            'touserid' => $tousers,
            'tocontactid' => $tocontacts,
            'eid' => $invoice_id,
            'brandid' => get_user_session(),
            'not_type' => "Invoices",
            'link' => 'invoices/list_invoices#' . $invoice_id,
            'additional_data' => ($integration == false ? serialize(array(
                $invoice_number
            )) : serialize(array()))
        );
        if (add_notification($notification_data)) {
            pusher_trigger_notification(array($tousers));
        }

    }

    //Added By Masud on 01/19/2018 End

    public function update_total_tax($id)
    {
        $total_tax = 0;
        $taxes = array();
        $_calculated_taxes = array();
        $invoice = $this->get($id);
        foreach ($invoice->items as $item) {
            $item_taxes = get_invoice_item_taxes($item['id']);
            if (count($item_taxes) > 0) {
                foreach ($item_taxes as $tax) {
                    $calc_tax = 0;
                    $tax_not_calc = false;
                    if (!in_array($tax['taxname'], $_calculated_taxes)) {
                        array_push($_calculated_taxes, $tax['taxname']);
                        $tax_not_calc = true;
                    }
                    if ($tax_not_calc == true) {
                        $taxes[$tax['taxname']] = array();
                        $taxes[$tax['taxname']]['total'] = array();
                        array_push($taxes[$tax['taxname']]['total'], (($item['qty'] * $item['rate']) / 100 * $tax['taxrate']));
                        $taxes[$tax['taxname']]['tax_name'] = $tax['taxname'];
                        $taxes[$tax['taxname']]['taxrate'] = $tax['taxrate'];
                    } else {
                        array_push($taxes[$tax['taxname']]['total'], (($item['qty'] * $item['rate']) / 100 * $tax['taxrate']));
                    }
                }
            }
        }
        foreach ($taxes as $tax) {
            $total = array_sum($tax['total']);
            if ($invoice->discount_percent != 0 && $invoice->discount_type == 'before_tax') {
                $total_tax_calculated = ($total * $invoice->discount_percent) / 100;
                $total = ($total - $total_tax_calculated);
            }
            $total_tax += $total;
        }
        $this->db->where('id', $id);
        $this->db->update('tblinvoices', array(
            'total_tax' => $total_tax
        ));
    }

    public function get_expenses_to_bill($clientid)
    {
        $this->load->model('expenses_model');
        $where = 'billable=1 AND clientid=' . $clientid . ' AND invoiceid IS NULL';
        if (!has_permission('expenses', '', 'view')) {
            $where .= ' AND addedfrom=' . get_staff_user_id();
        }

        return $this->expenses_model->get('', $where);
    }

    public function check_for_merge_invoice($client_id, $current_invoice)
    {
        if ($current_invoice != 'undefined') {
            $this->db->select('status');
            $this->db->where('id', $current_invoice);
            $row = $this->db->get('tblinvoices')->row();
            // Cant merge on paid invoice and partialy paid and cancelled
            if ($row->status == 2 || $row->status == 3 || $row->status == 5) {
                return array();
            }
        }

        $statuses = array(
            1,
            4,
            6
        );

        $has_permission_view = has_permission('invoices', '', 'view');
        $this->db->select('id');
        $this->db->where('clientid', $client_id);
        $this->db->where('STATUS IN (' . implode(', ', $statuses) . ')');
        if (!$has_permission_view) {
            $this->db->where('addedfrom', get_staff_user_id());
        }
        if ($current_invoice != 'undefined') {
            $this->db->where('id !=', $current_invoice);
        }


        $invoices = $this->db->get('tblinvoices')->result_array();
        $_invoices = array();
        foreach ($invoices as $invoice) {
            $_invoices[] = $this->get($invoice['id']);
        }
        return $_invoices;
    }

    /**
     * Copy invoice
     * @param  mixed $id invoice id to copy
     * @return mixed
     */
    public function copy($id)
    {
        $_invoice = $this->get($id);
        $new_invoice_data = array();
        $new_invoice_data['clientid'] = $_invoice->clientid;
        $new_invoice_data['number'] = get_option('next_invoice_number');
        $new_invoice_data['date'] = _d(date('Y-m-d'));

        if ($_invoice->duedate && get_option('invoice_due_after') != 0) {
            $new_invoice_data['duedate'] = _d(date('Y-m-d', strtotime('+' . get_option('invoice_due_after') . ' DAY', strtotime(date('Y-m-d')))));
        }

        $new_invoice_data['save_as_draft'] = true;
        $new_invoice_data['recurring_type'] = $_invoice->recurring_type;
        $new_invoice_data['custom_recurring'] = $_invoice->custom_recurring;
        $new_invoice_data['show_quantity_as'] = $_invoice->show_quantity_as;
        $new_invoice_data['currency'] = $_invoice->currency;
        $new_invoice_data['subtotal'] = $_invoice->subtotal;
        $new_invoice_data['total'] = $_invoice->total;
        $new_invoice_data['adminnote'] = $_invoice->adminnote;
        $new_invoice_data['adjustment'] = $_invoice->adjustment;
        $new_invoice_data['discount_percent'] = $_invoice->discount_percent;
        $new_invoice_data['discount_total'] = $_invoice->discount_total;
        $new_invoice_data['recurring'] = $_invoice->recurring;
        $new_invoice_data['discount_type'] = $_invoice->discount_type;
        $new_invoice_data['terms'] = $_invoice->terms;
        $new_invoice_data['sale_agent'] = $_invoice->sale_agent;
        $new_invoice_data['project_id'] = $_invoice->project_id;
        $new_invoice_data['recurring_ends_on'] = $_invoice->recurring_ends_on;
        // Since version 1.0.6
        $new_invoice_data['billing_street'] = $_invoice->billing_street;
        $new_invoice_data['billing_city'] = $_invoice->billing_city;
        $new_invoice_data['billing_state'] = $_invoice->billing_state;
        $new_invoice_data['billing_zip'] = $_invoice->billing_zip;
        $new_invoice_data['billing_country'] = $_invoice->billing_country;
        $new_invoice_data['shipping_street'] = $_invoice->shipping_street;
        $new_invoice_data['shipping_city'] = $_invoice->shipping_city;
        $new_invoice_data['shipping_state'] = $_invoice->shipping_state;
        $new_invoice_data['shipping_zip'] = $_invoice->shipping_zip;
        $new_invoice_data['shipping_country'] = $_invoice->shipping_country;
        if ($_invoice->include_shipping == 1) {
            $new_invoice_data['include_shipping'] = $_invoice->include_shipping;
        }
        $new_invoice_data['show_shipping_on_invoice'] = $_invoice->show_shipping_on_invoice;
        // Set to unpaid status automatically
        $new_invoice_data['status'] = 1;
        $new_invoice_data['clientnote'] = $_invoice->clientnote;
        $new_invoice_data['adminnote'] = $_invoice->adminnote;
        $new_invoice_data['allowed_payment_modes'] = unserialize($_invoice->allowed_payment_modes);
        $new_invoice_data['newitems'] = array();
        $key = 1;
        foreach ($_invoice->items as $item) {
            $new_invoice_data['newitems'][$key]['description'] = $item['description'];
            $new_invoice_data['newitems'][$key]['long_description'] = clear_textarea_breaks($item['long_description']);
            $new_invoice_data['newitems'][$key]['qty'] = $item['qty'];
            $new_invoice_data['newitems'][$key]['unit'] = $item['unit'];
            $new_invoice_data['newitems'][$key]['taxname'] = array();
            $taxes = get_invoice_item_taxes($item['id']);
            foreach ($taxes as $tax) {
                // tax name is in format TAX1|10.00
                array_push($new_invoice_data['newitems'][$key]['taxname'], $tax['taxname']);
            }
            $new_invoice_data['newitems'][$key]['rate'] = $item['rate'];
            $new_invoice_data['newitems'][$key]['order'] = $item['item_order'];
            $key++;
        }
        $id = $this->invoices_model->add($new_invoice_data);
        if ($id) {
            $this->db->where('id', $id);
            $this->db->update('tblinvoices', array(
                'cancel_overdue_reminders' => $_invoice->cancel_overdue_reminders
            ));

            $custom_fields = get_custom_fields('invoice');
            foreach ($custom_fields as $field) {
                $value = get_custom_field_value($_invoice->id, $field['id'], 'invoice');
                if ($value == '') {
                    continue;
                }
                $this->db->insert('tblcustomfieldsvalues', array(
                    'relid' => $id,
                    'fieldid' => $field['id'],
                    'fieldto' => 'invoice',
                    'value' => $value
                ));
            }
            logActivity('Copied Invoice ' . format_invoice_number($_invoice->id));

            do_action('invoice_copied', array('copy_from' => $_invoice->id, 'copy_id' => $id));

            return $id;
        }

        return false;
    }

    /**
     * Update invoice data
     * @param  array $data invoice data
     * @param  mixed $id invoiceid
     * @return boolean
     */
    public function update($data, $id)
    {
        $original_invoice = $this->get($id);
        $saveAndSend = false;
        if (isset($data['save_and_send'])) {
            $data['status'] = 1;
            $saveAndSend = true;
            unset($data['save_and_send']);
        }

        unset($data['hdnlid']);

        // From the top checkboxes bill expenses to merge in invoice, no need for this in the update function
        if (isset($data['bill_expenses'])) {
            unset($data['bill_expenses']);
        }

        if (isset($data['invoices_to_merge'])) {
            $invoices_to_merge = $data['invoices_to_merge'];
            unset($data['invoices_to_merge']);
        }
        if (isset($data['cancel_merged_invoices'])) {
            $cancel_merged_invoices = true;
            unset($data['cancel_merged_invoices']);
        }
        if (isset($data['project_id']) && $data['project_id'] == '' || !isset($data['project_id'])) {
            $data['project_id'] = 0;
        }

        if (isset($data['recurring_ends_on'])) {
            if ($data['recurring_ends_on'] == '') {
                $data['recurring_ends_on'] = null;
            } else {
                $data['recurring_ends_on'] = to_sql_date($data['recurring_ends_on']);
            }
        }

        $affectedRows = 0;
        $data['number'] = trim($data['number']);
        $original_number_formatted = format_invoice_number($id);
        $original_number = $original_invoice->number;

        if (isset($data['billed_tasks'])) {
            $billed_tasks = $data['billed_tasks'];
            unset($data['billed_tasks']);
        }

        if (isset($data['billed_expenses'])) {
            $billed_expenses = array_map("unserialize", array_unique(array_map("serialize", $data['billed_expenses'])));
            unset($data['billed_expenses']);
        }

        if (isset($data['cancel_overdue_reminders'])) {
            $data['cancel_overdue_reminders'] = 1;
        } else {
            $data['cancel_overdue_reminders'] = 0;
        }

        if (isset($data['recurring'])) {
            if ($data['recurring'] == 'custom') {
                $data['recurring_type'] = $data['repeat_type_custom'];
                $data['custom_recurring'] = 1;
                $data['recurring'] = $data['repeat_every_custom'];
            } else {
                $data['recurring_type'] = null;
                $data['custom_recurring'] = 0;
            }
        } else {
            $data['custom_recurring'] = 0;
            $data['recurring'] = 0;
            $data['recurring_type'] = null;
        }
        $unsetters = array(
            'currency_symbol',
            'price',
            'taxname',
            'taxid',
            'isedit',
            'unit',
            'description',
            'long_description',
            'tax',
            'rate',
            'quantity',
            'item_select',
            'item_group_select',
            'task_select',
            'task_id',
            'expense_id',
            'repeat_every_custom',
            'repeat_type_custom',
            'merge_current_invoice',
        );
        foreach ($unsetters as $u) {
            if (isset($u)) {
                unset($data[$u]);
            }
        }

        $items = array();
        if (isset($data['items'])) {
            $items = $data['items'];
            unset($data['items']);
        }
        $newitems = array();
        if (isset($data['newitems'])) {
            $newitems = $data['newitems'];
            unset($data['newitems']);
        }

        if ((isset($data['adjustment']) && !is_numeric($data['adjustment'])) || !isset($data['adjustment'])) {
            $data['adjustment'] = 0;
        } elseif (isset($data['adjustment']) && is_numeric($data['adjustment'])) {
            $data['adjustment'] = number_format($data['adjustment'], get_decimal_places(), '.', '');
        }

        if (!isset($data['include_shipping'])) {
            foreach ($this->shipping_fields as $_s_field) {
                if (isset($data[$_s_field])) {
                    $data[$_s_field] = null;
                }
            }
            $data['show_shipping_on_invoice'] = 1;
            $data['include_shipping'] = 0;
        } else {
            $data['include_shipping'] = 1;
            // set by default for the next time to be checked
            if (isset($data['show_shipping_on_invoice'])) {
                $data['show_shipping_on_invoice'] = 1;
            } else {
                $data['show_shipping_on_invoice'] = 0;
            }
        }
        if ($data['sale_agent'] == '') {
            $data['sale_agent'] = 0;
        }
        // Since version 1.0.1
        if (isset($data['allowed_payment_modes'])) {
            $data['allowed_payment_modes'] = serialize($data['allowed_payment_modes']);
        } else {
            $data['allowed_payment_modes'] = serialize(array());
        }

        $data['terms'] = nl2br_save_html($data['terms']);
        $data['clientnote'] = nl2br_save_html($data['clientnote']);

        if (isset($data['adminnote'])) {
            $data['adminnote'] = nl2br($data['adminnote']);
        }

        $data['date'] = to_sql_date($data['date']);
        if (!empty($data['duedate'])) {
            $data['duedate'] = to_sql_date($data['duedate']);
        } else {
            $data['duedate'] = null;
        }
        if (isset($data['discount_total']) && $data['discount_total'] == 0) {
            $data['discount_type'] = '';
        }
        if (isset($data['custom_fields'])) {
            $custom_fields = $data['custom_fields'];
            if (handle_custom_fields_post($id, $custom_fields)) {
                $affectedRows++;
            }
            unset($data['custom_fields']);
        }
        $action_data = array(
            'data' => $data,
            'newitems' => $newitems,
            'items' => $items,
            'id' => $id,
            'removed_items' => array()
        );
        if (isset($data['removed_items'])) {
            $action_data['removed_items'] = $data['removed_items'];
        }
        $_data = do_action('before_invoice_updated', $action_data);
        $data['removed_items'] = $_data['removed_items'];
        $newitems = $_data['newitems'];
        $items = $_data['items'];
        $data = $_data['data'];
        if (isset($billed_tasks)) {
            foreach ($billed_tasks as $key => $tasks) {
                foreach ($tasks as $t) {
                    $_task = $this->tasks_model->get($t);
                    $_task_data = array(
                        'billed' => 1,
                        'invoice_id' => $id
                    );
                    if ($_task->status != 5) {
                        $_task_data['status'] = 5;
                        $_task_data['datefinished'] = date('Y-m-d H:i:s');
                    }
                    $this->db->where('id', $t);
                    $this->db->update('tblstafftasks', $_task_data);
                }
            }
        }
        if (isset($billed_expenses)) {
            foreach ($billed_expenses as $key => $val) {
                foreach ($val as $expense_id) {
                    $this->db->where('id', $expense_id);
                    $this->db->update('tblexpenses', array(
                        'invoiceid' => $id
                    ));
                }
            }
        }
        // Delete items checked to be removed from database
        if (isset($data['removed_items'])) {
            foreach ($data['removed_items'] as $remove_item_id) {
                $original_item = $this->get_invoice_item($remove_item_id);
                $this->db->where('id', $remove_item_id);
                $this->db->delete('tblitems_in');
                if ($this->db->affected_rows() > 0) {
                    $this->log_invoice_activity($id, 'invoice_estimate_activity_removed_item', false, serialize(array(
                        $original_item->description
                    )));
                    $affectedRows++;

                    $this->db->where('itemid', $remove_item_id);
                    $this->db->where('rel_type', 'invoice');
                    $this->db->delete('tblitemstax');

                    $this->db->where('item_id', $original_item->id);
                    $related_items = $this->db->get('tblitemsrelated')->result_array();
                    foreach ($related_items as $rel_item) {
                        if ($rel_item['rel_type'] == 'task') {
                            $this->db->where('id', $rel_item['rel_id']);
                            $this->db->update('tblstafftasks', array(
                                'invoice_id' => null,
                                'billed' => 0
                            ));
                        } elseif ($rel_item['rel_type'] == 'expense') {
                            $this->db->where('id', $rel_item['rel_id']);
                            $this->db->update('tblexpenses', array(
                                'invoiceid' => null
                            ));
                        }
                        $this->db->where('item_id', $original_item->id);
                        $this->db->delete('tblitemsrelated');
                    }
                }

                $this->db->where('itemid', $remove_item_id);
                $this->db->where('rel_type', 'invoice');
                $this->db->delete('tblitemstax');
            }
            unset($data['removed_items']);
        }
        unset($data['item_id']);
        $this->db->where('id', $id);
        $this->db->update('tblinvoices', $data);
        if ($this->db->affected_rows() > 0) {

            //Added By Masud on 01/19/2018 start
            $this->invoice_update_notification($id, $data['sale_agent'], $data['clientid']);

            //Added By Masud on 01/19/2018 end
            $affectedRows++;
            if ($original_number != $data['number']) {
                $this->log_invoice_activity($original_invoice->id, 'invoice_activity_number_changed', false, serialize(array(
                    $original_number_formatted,
                    format_invoice_number($original_invoice->id)
                )));
            }
        }
        $this->load->model('taxes_model');
        if (count($items) > 0) {
            foreach ($items as $key => $item) {
                $invoice_item_id = $item['itemid'];
                $original_item = $this->get_invoice_item($invoice_item_id);
                $this->db->where('id', $invoice_item_id);
                $this->db->update('tblitems_in', array(
                    'item_order' => $item['order'],
                    'unit' => isset($item['unit']) ? $item['unit'] : ''
                ));
                if ($this->db->affected_rows() > 0) {
                    $affectedRows++;
                }

                // Check for invoice item short description change
                $this->db->where('id', $invoice_item_id);
                $this->db->update('tblitems_in', array(
                    'description' => $item['description']
                ));
                if ($this->db->affected_rows() > 0) {
                    $this->log_invoice_activity($id, 'invoice_estimate_activity_updated_item_short_description', false, serialize(array(
                        $original_item->description,
                        $item['description']
                    )));
                    $affectedRows++;
                }
                // Check for item long description change
                $this->db->where('id', $invoice_item_id);
                $this->db->update('tblitems_in', array(
                    'long_description' => isset($item['long_description']) ? nl2br($item['long_description']) : ''
                ));
                if ($this->db->affected_rows() > 0) {
                    $this->log_invoice_activity($id, 'invoice_estimate_activity_updated_item_long_description', false, serialize(array(
                        $original_item->long_description,
                        $item['long_description']
                    )));
                    $affectedRows++;
                }
                if (!isset($item['taxname']) || (isset($item['taxname']) && count($item['taxname']) == 0)) {
                    $this->db->where('itemid', $invoice_item_id);
                    $this->db->where('rel_type', 'invoice');
                    $this->db->delete('tblitemstax');
                } else {
                    $item_taxes = get_invoice_item_taxes($invoice_item_id);
                    $_item_taxes_names = array();
                    foreach ($item_taxes as $_item_tax) {
                        array_push($_item_taxes_names, $_item_tax['taxname']);
                    }
                    $i = 0;
                    foreach ($_item_taxes_names as $_item_tax) {
                        if (!in_array($_item_tax, $item['taxname'])) {
                            $this->db->where('id', $item_taxes[$i]['id']);
                            $this->db->delete('tblitemstax');
                            if ($this->db->affected_rows() > 0) {
                                $affectedRows++;
                            }
                        }
                        $i++;
                    }
                    if (isset($item['taxname']) && is_array($item['taxname'])) {
                        foreach ($item['taxname'] as $taxname) {
                            if ($taxname != '') {
                                $tax_array = explode('|', $taxname);
                                $tax_name = trim($tax_array[0]);
                                $tax_rate = trim($tax_array[1]);
                                if (total_rows('tblitemstax', array(
                                        'taxname' => $tax_name,
                                        'itemid' => $invoice_item_id,
                                        'taxrate' => $tax_rate,
                                        'rel_type' => 'invoice',
                                        'rel_id' => $id
                                    )) == 0) {
                                    $this->db->insert('tblitemstax', array(
                                        'taxrate' => $tax_rate,
                                        'taxname' => $tax_name,
                                        'itemid' => $invoice_item_id,
                                        'rel_id' => $id,
                                        'rel_type' => 'invoice'
                                    ));
                                    if ($this->db->affected_rows() > 0) {
                                        $affectedRows++;
                                    }
                                }
                            }
                        }
                    }
                }
                // Check for item rate change
                $this->db->where('id', $invoice_item_id);
                $this->db->update('tblitems_in', array(
                    'rate' => number_format($item['rate'], get_decimal_places(), '.', '')
                ));
                if ($this->db->affected_rows() > 0) {
                    $this->log_invoice_activity($id, 'invoice_estimate_activity_updated_item_rate', false, serialize(array(
                        $original_item->rate,
                        $item['rate']
                    )));
                    $affectedRows++;
                }
                // CHeck for invoice quantity change
                $this->db->where('id', $invoice_item_id);
                $this->db->update('tblitems_in', array(
                    'qty' => $item['qty']
                ));
                if ($this->db->affected_rows() > 0) {
                    $this->log_invoice_activity($id, 'invoice_estimate_activity_updated_qty_item', false, serialize(array(
                        $item['description'],
                        $original_item->qty,
                        $item['qty']
                    )));
                    $affectedRows++;
                }
            }
        }
        if (count($newitems) > 0) {
            foreach ($newitems as $key => $item) {
                $this->db->insert('tblitems_in', array(
                    'description' => $item['description'],
                    'long_description' => isset($item['long_description']) ? nl2br($item['long_description']) : '',
                    'qty' => $item['qty'],
                    'rate' => number_format($item['rate'], get_decimal_places(), '.', ''),
                    'rel_id' => $id,
                    'rel_type' => 'invoice',
                    'item_order' => $item['order'],
                    'unit' => isset($item['unit']) ? $item['unit'] : ''
                ));
                $new_item_added = $this->db->insert_id();
                if ($new_item_added) {
                    if (isset($billed_tasks[$key])) {
                        foreach ($billed_tasks[$key] as $_task_id) {
                            $this->db->insert('tblitemsrelated', array(
                                'item_id' => $new_item_added,
                                'rel_id' => $_task_id,
                                'rel_type' => 'task'
                            ));
                        }
                    } elseif (isset($billed_expenses[$key])) {
                        foreach ($billed_expenses[$key] as $_expense_id) {
                            $this->db->insert('tblitemsrelated', array(
                                'item_id' => $new_item_added,
                                'rel_id' => $_expense_id,
                                'rel_type' => 'expense'
                            ));
                        }
                    }
                    if (isset($item['taxname']) && is_array($item['taxname'])) {
                        foreach ($item['taxname'] as $taxname) {
                            if ($taxname != '') {
                                $tax_array = explode('|', $taxname);
                                if (isset($tax_array[0]) && isset($tax_array[1])) {
                                    $tax_name = trim($tax_array[0]);
                                    $tax_rate = trim($tax_array[1]);
                                    if (total_rows('tblitemstax', array(
                                            'taxrate' => $tax_rate,
                                            'taxname' => $tax_name,
                                            'itemid' => $new_item_added,
                                            'rel_id' => $id,
                                            'rel_type' => 'invoice')) == 0) {
                                        $this->db->insert('tblitemstax', array(
                                            'taxrate' => $tax_rate,
                                            'taxname' => $tax_name,
                                            'itemid' => $new_item_added,
                                            'rel_id' => $id,
                                            'rel_type' => 'invoice'
                                        ));
                                        if ($this->db->affected_rows() > 0) {
                                            $affectedRows++;
                                        }
                                    }
                                }
                            }
                        }
                    }
                    $this->log_invoice_activity($id, 'invoice_estimate_activity_added_item', false, serialize(array(
                        $item['description']
                    )));
                    $affectedRows++;
                }
            }
        }
        if (isset($invoices_to_merge)) {
            $_merged = false;
            foreach ($invoices_to_merge as $m) {
                $or_merge = $this->get($m);
                if (!isset($cancel_merged_invoices)) {
                    if ($this->delete($m, true)) {
                        $_merged = true;
                    }
                } else {
                    if ($this->mark_as_cancelled($m)) {
                        $_merged = true;
                        $admin_note = $or_merge->adminnote;
                        $note = 'Merged into invoice ' . format_invoice_number($id);
                        if ($admin_note != '') {
                            $admin_note .= "\n\r" . $note;
                        } else {
                            $admin_note = $note;
                        }
                        $this->db->where('id', $m);
                        $this->db->update('tblinvoices', array(
                            'adminnote' => $admin_note
                        ));
                    }
                }
                if ($_merged) {
                    $this->db->where('invoiceid', $or_merge->id);
                    $is_expense_invoice = $this->db->get('tblexpenses')->row();
                    if ($is_expense_invoice) {
                        $this->db->where('id', $is_expense_invoice->id);
                        $this->db->update('tblexpenses', array(
                            'invoiceid' => $id
                        ));
                    }
                    if (total_rows('tblestimates', array(
                            'invoiceid' => $or_merge->id
                        )) > 0) {
                        $this->db->where('invoiceid', $or_merge->id);
                        $estimate = $this->db->get('tblestimates')->row();
                        $this->db->where('id', $estimate->id);
                        $this->db->update('tblestimates', array(
                            'invoiceid' => $id
                        ));
                    } elseif (total_rows('tblproposals', array(
                            'invoice_id' => $or_merge->id
                        )) > 0) {
                        $this->db->where('invoice_id', $or_merge->id);
                        $proposal = $this->db->get('tblproposals')->row();
                        $this->db->where('id', $proposal->id);
                        $this->db->update('tblproposals', array(
                            'invoice_id' => $id
                        ));
                    }
                }
            }
        }

        if ($affectedRows > 0) {
            $this->update_total_tax($id);
            update_invoice_status($id);
        }

        if ($saveAndSend === true) {
            $this->send_invoice_to_client($id, '', true, '', true);
        }
        if ($affectedRows > 0) {
            do_action('after_invoice_updated', $id);
            return true;
        }
        return false;
    }

    public function get_attachments($invoiceid, $id = '')
    {
        // If is passed id get return only 1 attachment
        if (is_numeric($id)) {
            $this->db->where('id', $id);
        } else {
            $this->db->where('rel_id', $invoiceid);
        }
        $this->db->where('rel_type', 'invoice');
        $result = $this->db->get('tblfiles');
        if (is_numeric($id)) {
            return $result->row();
        } else {
            return $result->result_array();
        }
    }

    /**
     *  Delete invoice attachment
     * @since  Version 1.0.4
     * @param   mixed $id attachmentid
     * @return  boolean
     */
    public function delete_attachment($id)
    {
        $attachment = $this->get_attachments('', $id);
        $deleted = false;
        if ($attachment) {
            if (empty($attachment->external)) {
                unlink(get_upload_path_by_type('invoice') . $attachment->rel_id . '/' . $attachment->file_name);
            }
            $this->db->where('id', $attachment->id);
            $this->db->delete('tblfiles');
            if ($this->db->affected_rows() > 0) {
                $deleted = true;
                logActivity('Invoice Attachment Deleted [InvoiceID: ' . $attachment->rel_id . ']');
            }

            if (is_dir(get_upload_path_by_type('invoice') . $attachment->rel_id)) {
                // Check if no attachments left, so we can delete the folder also
                $other_attachments = list_files(get_upload_path_by_type('invoice') . $attachment->rel_id);
                if (count($other_attachments) == 0) {
                    // okey only index.html so we can delete the folder also
                    delete_dir(get_upload_path_by_type('invoice') . $attachment->rel_id);
                }
            }
        }

        return $deleted;
    }

    /**
     * Delete invoice items and all connections
     * @param  mixed $id invoiceid
     * @return boolean
     */
    public function delete($id, $merge = false)
    {
        if (get_option('delete_only_on_last_invoice') == 1 && $merge == false) {
            if (!is_last_invoice($id)) {
                return false;
            }
        }
        $number = format_invoice_number($id);

        do_action('before_invoice_deleted', $id);
        $this->db->where('id', $id);
        $this->db->delete('tblinvoices');
        if ($this->db->affected_rows() > 0) {
            if (get_option('invoice_number_decrement_on_delete') == 1 && $merge == false) {
                $current_next_invoice_number = get_option('next_invoice_number');
                if ($current_next_invoice_number > 1) {
                    // Decrement next invoice number to
                    $this->db->where('name', 'next_invoice_number');
                    $this->db->set('value', 'value-1', false);
                    $this->db->update('tbloptions');
                }
            }
            if ($merge == false) {
                $this->db->where('invoiceid', $id);
                $this->db->update('tblexpenses', array(
                    'invoiceid' => null
                ));

                $this->db->where('invoice_id', $id);
                $this->db->update('tblproposals', array(
                    'invoice_id' => null,
                    'date_converted' => null
                ));

                $this->db->where('invoice_id', $id);
                $this->db->update('tblstafftasks', array(
                    'invoice_id' => null,
                    'billed' => 0
                ));

                // if is converted from estimate set the estimate invoice to null
                if (total_rows('tblestimates', array(
                        'invoiceid' => $id
                    )) > 0) {
                    $this->db->where('invoiceid', $id);
                    $estimate = $this->db->get('tblestimates')->row();
                    $this->db->where('id', $estimate->id);
                    $this->db->update('tblestimates', array(
                        'invoiceid' => null,
                        'invoiced_date' => null
                    ));
                    $this->load->model('estimates_model');
                    $this->estimates_model->log_estimate_activity($estimate->id, 'not_estimate_invoice_deleted');
                }
            }
            $this->db->where('rel_type', 'invoice');
            $this->db->where('rel_id', $id);
            $this->db->delete('tblreminders');

            $this->db->where('rel_type', 'invoice');
            $this->db->where('rel_id', $id);
            $this->db->delete('tblviewstracking');

            $items = $this->get_invoice_items($id);
            $this->db->where('rel_id', $id);
            $this->db->where('rel_type', 'invoice');
            $this->db->delete('tblitems_in');

            foreach ($items as $item) {
                $this->db->where('item_id', $item['id']);
                $this->db->delete('tblitemsrelated');
            }
            $this->db->where('invoiceid', $id);
            $this->db->delete('tblinvoicepaymentrecords');

            $this->db->where('rel_id', $id);
            $this->db->where('rel_type', 'invoice');
            $this->db->delete('tblsalesactivity');

            $this->db->where('is_recurring_from', $id);
            $this->db->update('tblinvoices', array(
                'is_recurring_from' => null
            ));

            // Delete the custom field values
            $this->db->where('relid', $id);
            $this->db->where('fieldto', 'invoice');
            $this->db->delete('tblcustomfieldsvalues');

            $this->db->where('rel_id', $id);
            $this->db->where('rel_type', 'invoice');
            $this->db->delete('tblitemstax');

            // Get billed tasks for this invoice and set to unbilled
            $this->db->where('invoice_id', $id);
            $tasks = $this->db->get('tblstafftasks')->result_array();
            foreach ($tasks as $task) {
                $this->db->where('id', $task['id']);
                $this->db->update('tblstafftasks', array(
                    'invoice_id' => null,
                    'billed' => 0
                ));
            }

            $attachments = $this->get_attachments($id);
            foreach ($attachments as $attachment) {
                $this->delete_attachment($attachment['id']);
            }
            // Get related tasks
            $this->db->where('rel_type', 'invoice');
            $this->db->where('rel_id', $id);
            $tasks = $this->db->get('tblstafftasks')->result_array();
            foreach ($tasks as $task) {
                $this->tasks_model->delete_task($task['id']);
            }
            if ($merge == false) {
                logActivity('Invoice Deleted [' . $number . ']');
            }

            return true;
        }

        return false;
    }

    /**
     * Set invoice to sent when email is successfuly sended to client
     * @param mixed $id invoiceid
     * @param  mixed $manually is staff manually marking this invoice as sent
     * @return  boolean
     */
    public function set_invoice_sent($id, $manually = false, $emails_sent = array(), $is_status_updated = false)
    {
        $this->db->where('id', $id);
        $this->db->update('tblinvoices', array(
            'sent' => 1,
            'datesend' => date('Y-m-d H:i:s')
        ));
        $marked = false;
        if ($this->db->affected_rows() > 0) {
            $marked = true;
        }
        if (DEFINED('CRON')) {
            $additional_activity_data = serialize(array(
                '<custom_data>' . implode(', ', $emails_sent) . '</custom_data>'
            ));
            $description = 'invoice_activity_sent_to_client_cron';
        } else {
            if ($manually == false) {
                $additional_activity_data = serialize(array(
                    '<custom_data>' . implode(', ', $emails_sent) . '</custom_data>'
                ));
                $description = 'invoice_activity_sent_to_client';
            } else {
                $additional_activity_data = serialize(array());
                $description = 'invoice_activity_marked_as_sent';
            }
        }

        if ($is_status_updated == false) {
            update_invoice_status($id, true);
        }

        $this->log_invoice_activity($id, $description, false, $additional_activity_data);

        return $marked;
    }

    /**
     * Sent overdue notice to client for this invoice
     * @since  Since Version 1.0.1
     * @param  mxied $id invoiceid
     * @return boolean
     */
    public function send_invoice_overdue_notice($id)
    {
        $this->load->model('emails_model');
        $this->emails_model->set_rel_id($id);
        $this->emails_model->set_rel_type('invoice');

        $invoice = $this->get($id);
        if (isset($invoice->client->phone->phone)) {
            $invoice->phonenumber = $invoice->client->phone->phone;
        } else {
            $invoice->phonenumber = '';
        }
        $invoice_number = format_invoice_number($invoice->id);
        $pdf = invoice_pdf($invoice);
        $attach = $pdf->Output($invoice_number . '.pdf', 'S');
        $emails_sent = array();
        $send = false;
        $contacts = $this->addressbooks_model->get_contacts($invoice->clientid);
        // echo "<pre>";print_r($contacts);exit;
        // foreach ($contacts as $contact) { die("here");
        //  echo "<pre>";print_r($contact);exit;
        $this->emails_model->add_attachment(array(
            'attachment' => $attach,
            'filename' => $invoice_number . '.pdf',
            'type' => 'application/pdf'
        ));
        $merge_fields = array();
        $merge_fields = array_merge($merge_fields, get_client_contact_merge_fields($invoice->clientid, $contacts->addressbookid));
        $merge_fields = array_merge($merge_fields, get_invoice_merge_fields($invoice->id));
        if ($this->emails_model->send_email_template('invoice-overdue-notice', $contacts->email->email, $merge_fields)) {
            array_push($emails_sent, $contacts->email->email);
            $send = true;
        }

        //}
        if ($send) {
            if (DEFINED('CRON')) {
                $_from = '[CRON]';
            } else {
                $_from = get_staff_full_name();
            }

            $this->db->where('id', $id);
            $this->db->update('tblinvoices', array(
                'last_overdue_reminder' => date('Y-m-d')
            ));
            $this->log_invoice_activity($id, 'user_sent_overdue_reminder', false, serialize(array(
                '<custom_data>' . implode(', ', $emails_sent) . '</custom_data>',
                $_from
            )));

            do_action('invoice_overdue_reminder_sent', array('sent_to' => $emails_sent, 'invoice_id' => $id));

            return true;
        }

        return false;
    }

    /**
     * Send invoice to client
     * @param  mixed $id invoiceid
     * @param  string $template email template to sent
     * @param  boolean $attachpdf attach invoice pdf or not
     * @return boolean
     */
    public function send_invoice_to_client($id, $template = '', $attachpdf = true, $cc = '', $manually = false)
    {

        $this->load->model('emails_model');

        $this->emails_model->set_rel_id($id);
        $this->emails_model->set_rel_type('invoice');

        $invoice = $this->get($id);
        if ($template == '') {
            if ($invoice->sent == 0) {
                $template = 'invoice-send-to-client';
            } else {
                $template = 'invoice-already-send';
            }
            $template = do_action('after_invoice_sent_template_statement', $template);
        }
        $invoice_number = format_invoice_number($invoice->id);

        $emails_sent = array();
        $send = false;
        // Manually is used when sending the invoice via add/edit area button Save & Send
        if (!DEFINED('CRON') && $manually === false) {
            $sent_to = $this->input->post('sent_to');
        } else {
            $sent_to = array();
            //$contacts = $this->clients_model->get_contacts($invoice->clientid);
            //$contacts = $this->addressbooks_model->get($invoice->clientid);
            $contacts=$invoice->clients;

            foreach ($contacts as $contact) {
                $contact = (array)$contact;
                array_push($sent_to, $contact['addressbookid']);
                /*if (has_contact_permission('invoices', $contact['addressbookid'])) {
                    array_push($sent_to, $contact['addressbookid']);
                }*/
            }
        }
        if (is_array($sent_to) && count($sent_to) > 0) {
            $status_updated = update_invoice_status($invoice->id, true, true);

            if ($attachpdf) {
                $_pdf_invoice = $this->get($id);
                $pdf = invoice_pdf($_pdf_invoice);
                $attach = $pdf->Output($invoice_number . '.pdf', 'S');
            }

            $i = 0;
            foreach ($sent_to as $contact_id) {
                if ($contact_id != '') {
                    if ($attachpdf) {
                        $this->emails_model->add_attachment(array(
                            'attachment' => $attach,
                            'filename' => $invoice_number . '.pdf',
                            'type' => 'application/pdf'
                        ));
                    }
                    if ($this->input->post('email_attachments')) {
                        $_other_attachments = $this->input->post('email_attachments');
                        foreach ($_other_attachments as $attachment) {
                            $_attachment = $this->get_attachments($id, $attachment);
                            $this->emails_model->add_attachment(array(
                                'attachment' => get_upload_path_by_type('invoice') . $id . '/' . $_attachment->file_name,
                                'filename' => $_attachment->file_name,
                                'type' => $_attachment->filetype,
                                'read' => true
                            ));
                        }
                    }
                    $contact = $this->addressbooks_model->get($contact_id);
                    $contact->email = $contact->email[0]['email'];
                    $merge_fields = array();
                    $merge_fields = array_merge($merge_fields, get_client_contact_merge_fields($invoice->clientid, $contact_id));

                    $merge_fields = array_merge($merge_fields, get_invoice_merge_fields($invoice->id));
                    // Send cc only for the first contact
                    if (!empty($cc) && $i > 0) {
                        $cc = '';
                    }
                    if ($this->emails_model->send_email_template($template, $contact->email, $merge_fields, '', $cc)) {
                        $send = true;
                        array_push($emails_sent, $contact->email);
                    }
                }
                $i++;
            }
        } else {
            return false;
        }
        if ($send) {
            $this->set_invoice_sent($id, false, $emails_sent, true);

            return true;
        } else {
            // In case the invoice not sended and the status was draft and the invoiec status is updated before send return back to draft status
            if ($invoice->status == 6 && $status_updated !== false) {
                $this->db->where('id', $invoice->id);
                $this->db->update('tblinvoices', array(
                    'status' => 6
                ));
            }
        }

        return false;
    }

    /**
     * All invoice activity
     * @param  mixed $id invoiceid
     * @return array
     */
    public function get_invoice_activity($id)
    {
        $this->db->where('rel_id', $id);
        $this->db->where('rel_type', 'invoice');
        $this->db->order_by('date', 'desc');

        return $this->db->get('tblsalesactivity')->result_array();
    }

    /**
     * Log invoice activity to database
     * @param  mixed $id invoiceid
     * @param  string $description activity description
     */
    public function log_invoice_activity($id, $description = '', $client = false, $additional_data = '')
    {
        if (DEFINED('CRON')) {
            $staffid = '[CRON]';
            $full_name = '[CRON]';
        } elseif ($client == true) {
            $staffid = null;
            $full_name = '';
        } else {
            $staffid = get_staff_user_id();
            $full_name = get_staff_full_name(get_staff_user_id());
        }
        $this->db->insert('tblsalesactivity', array(
            'description' => $description,
            'date' => date('Y-m-d H:i:s'),
            'rel_id' => $id,
            'rel_type' => 'invoice',
            'staffid' => $staffid,
            'full_name' => $full_name,
            'additional_data' => $additional_data
        ));
    }

    public function get_invoices_years()
    {
        return $this->db->query('SELECT DISTINCT(YEAR(date)) as year FROM tblinvoices ORDER BY year DESC')->result_array();
    }

    //Added By Avni on 11/22/2017
    public function get_contacts($leadid = "", $projectid = "", $eventid = "")
    {
        $this->db->query('SET sql_mode=""');
        $brandid = get_user_session();
        if (isset($projectid) && $projectid > 0) {
            $this->db->select('id');
            $this->db->where('(parent = ' . $projectid . ' OR id = ' . $projectid . ')');
            $this->db->where('deleted', 0);
            $related_project_ids = $this->db->get('tblprojects')->result_array();
            $related_project_ids = array_column($related_project_ids, 'id');
            $related_project_ids = implode(",", $related_project_ids);
        } else {
            $related_project_ids = "";
        }

        if ($brandid > 0 && $leadid != "") {
            $this->db->join('tbladdressbook_client', 'tbladdressbook_client.addressbookid = tbladdressbook.addressbookid');
            $this->db->join('tblleadcontact', 'tblleadcontact.contactid = tbladdressbook.addressbookid');
            $this->db->where('tbladdressbook_client.deleted', 0);
            $this->db->where('tbladdressbook_client.brandid', $brandid);
            if ($leadid != "") {
                $this->db->where('tblleadcontact.leadid', $leadid);
            }
        }

        if ($brandid > 0 && $projectid != "") {
            $this->db->join('tbladdressbook_client', 'tbladdressbook_client.addressbookid = tbladdressbook.addressbookid');
            $this->db->join('tblprojectcontact', 'tblprojectcontact.contactid = tbladdressbook.addressbookid');
            $this->db->where('tbladdressbook_client.deleted', 0);
            $this->db->where('tbladdressbook_client.brandid', $brandid);
            if (!empty($related_project_ids)) {
                $this->db->where('tblprojectcontact.projectid in (' . $related_project_ids . ') OR tblprojectcontact.eventid in (' . $related_project_ids . ')');
            } else {
                $this->db->where('tblprojectcontact.projectid', $projectid);
            }
        }

        if ($brandid > 0 && $eventid != "") {
            $this->db->join('tbladdressbook_client', 'tbladdressbook_client.addressbookid = tbladdressbook.addressbookid');
            $this->db->join('tblprojectcontact', 'tblprojectcontact.contactid = tbladdressbook.addressbookid');
            $this->db->where('tbladdressbook_client.deleted', 0);
            $this->db->where('tbladdressbook_client.brandid', $brandid);
            if ($eventid != "") {
                $this->db->where('tblprojectcontact.eventid', $eventid);
            }
        }
        $this->db->group_by('tbladdressbook.addressbookid');

        $this->db->where('tbladdressbook.deleted', 0);
        return $this->db->get('tbladdressbook')->result_array();
    }

    public function get_next_invoice($lid, $lead_date = "")
    {
        $this->db->select('eventno, eventinvoiceno');
        $this->db->from('tblinvoices');
        $this->db->where('tblinvoices.brandid', get_user_session());
        $this->db->where('tblinvoices.leadid', $lid);
        $this->db->order_by('tblinvoices.id', 'desc');
        $this->db->limit(1);
        $lid_events = $this->db->get()->row();
        /*echo $this->db->last_query();
        echo "<pre>";
       print_r($lid_events);exit;*/
        $event_no = $event_invoice_no = "";
        if (empty($lid_events) || $lid_events->eventinvoiceno == "" || $lid_events->eventno == "") {
            $this->db->select('eventno');
            $this->db->from('tblinvoices');
            $this->db->where('tblinvoices.brandid', get_user_session());
            $this->db->where('tblinvoices.leaddate', $lead_date);
            $this->db->where('tblinvoices.eventno != ""');
            $this->db->group_by('tblinvoices.eventno');
            $this->db->order_by('tblinvoices.eventno', 'desc');
            $this->db->limit(1);
            $lid_new_invoice_no = $this->db->get()->row();
            if (!empty($lid_new_invoice_no)) {
                $lid_new_invoice_eventno = $lid_new_invoice_no->eventno;
                $lid_new_invoice_eventno = $lid_new_invoice_eventno + 1;
                $lid_new_invoice_eventno = sprintf("%02d", $lid_new_invoice_eventno);
                $event_no = $lid_new_invoice_eventno;
                $event_invoice_no = "01";
            } else {
                $event_no = "01";
                $event_invoice_no = "01";
            }
        } else {
            $eventinvoiceno = $lid_events->eventinvoiceno;
            $eventno = $lid_events->eventno;
            $eventinvoiceno = $eventinvoiceno + 1;
            $eventinvoiceno = sprintf("%02d", $eventinvoiceno);
            $event_no = $eventno;
            $event_invoice_no = $eventinvoiceno;
        }
        $data['event_no'] = $event_no;
        $data['event_invoice_no'] = $event_invoice_no;
        return $data;
    }

    public function get_next_invoice_project($pid, $project_date = "")
    {
        $this->db->select('eventno, eventinvoiceno');
        $this->db->from('tblinvoices');
        $this->db->where('tblinvoices.brandid', get_user_session());
        $this->db->where('tblinvoices.project_id', $pid);
        $this->db->order_by('tblinvoices.id', 'desc');
        $this->db->limit(1);
        $pid_events = $this->db->get()->row();
        /* echo $this->db->last_query();
         echo "<pre>";
        print_r($pid_events);exit;*/
        $event_no = $event_invoice_no = "";
        if (empty($pid_events) || $pid_events->eventinvoiceno == "" || $pid_events->eventno == "") {
            $this->db->select('eventno');
            $this->db->from('tblinvoices');
            $this->db->where('tblinvoices.brandid', get_user_session());
            $this->db->where('tblinvoices.leaddate', $project_date);
            $this->db->where('tblinvoices.eventno != ""');
            $this->db->group_by('tblinvoices.eventno');
            $this->db->order_by('tblinvoices.eventno', 'desc');
            $this->db->limit(1);
            $pid_new_invoice_no = $this->db->get()->row();
            if (!empty($pid_new_invoice_no)) {
                $pid_new_invoice_eventno = $pid_new_invoice_no->eventno;
                $pid_new_invoice_eventno = $pid_new_invoice_eventno + 1;
                $pid_new_invoice_eventno = sprintf("%02d", $pid_new_invoice_eventno);
                $event_no = $pid_new_invoice_eventno;
                $event_invoice_no = "01";
            } else {
                $event_no = "01";
                $event_invoice_no = "01";
            }
        } else {
            $eventinvoiceno = $pid_events->eventinvoiceno;
            $eventno = $pid_events->eventno;
            $eventinvoiceno = $eventinvoiceno + 1;
            $eventinvoiceno = sprintf("%02d", $eventinvoiceno);
            $event_no = $eventno;
            $event_invoice_no = $eventinvoiceno;
        }
        $data['event_no'] = $event_no;
        $data['event_invoice_no'] = $event_invoice_no;
        return $data;
    }

    public function get_next_invoice_event($eid, $event_date = "")
    {
        $this->db->select('eventno, eventinvoiceno');
        $this->db->from('tblinvoices');
        $this->db->where('tblinvoices.brandid', get_user_session());
        $this->db->where('tblinvoices.eventid', $eid);
        $this->db->order_by('tblinvoices.id', 'desc');
        $this->db->limit(1);
        $eid_events = $this->db->get()->row();
        // echo $this->db->last_query();
        // echo "<pre>";
        //print_r($eid_events);exit;
        $event_no = $event_invoice_no = "";
        if (empty($eid_events) || $eid_events->eventinvoiceno == "" || $eid_events->eventno == "") {
            $this->db->select('eventno');
            $this->db->from('tblinvoices');
            $this->db->where('tblinvoices.brandid', get_user_session());
            $this->db->where('tblinvoices.leaddate', $event_date);
            $this->db->where('tblinvoices.eventno != ""');
            $this->db->group_by('tblinvoices.eventno');
            $this->db->order_by('tblinvoices.eventno', 'desc');
            $this->db->limit(1);
            $eid_new_invoice_no = $this->db->get()->row();
            if (!empty($eid_new_invoice_no)) {
                $eid_new_invoice_eventno = $eid_new_invoice_no->eventno;
                $eid_new_invoice_eventno = $eid_new_invoice_eventno + 1;
                $eid_new_invoice_eventno = sprintf("%02d", $eid_new_invoice_eventno);
                $event_no = $eid_new_invoice_eventno;
                $event_invoice_no = "01";
            } else {
                $event_no = "01";
                $event_invoice_no = "01";
            }
        } else {
            $eventinvoiceno = $eid_events->eventinvoiceno;
            $eventno = $eid_events->eventno;
            $eventinvoiceno = $eventinvoiceno + 1;
            $eventinvoiceno = sprintf("%02d", $eventinvoiceno);
            $event_no = $eventno;
            $event_invoice_no = $eventinvoiceno;
        }
        $data['event_no'] = $event_no;
        $data['event_invoice_no'] = $event_invoice_no;
        return $data;
    }

    function rec_invoices()
    {
        $where1 = array('brandid' => get_user_session(), 'date' => date('Y-m-d'), 'status' => 1);
        $where2 = "( recurring > 0 OR is_recurring_from > 0)";

        $this->db->select('id');
        $this->db->from('tblinvoices');
        $this->db->where($where1);
        $this->db->where($where2);
        $invoices = $this->db->get()->result();
        return $invoices;
    }

    function get_by_relid($relid)
    {
        $this->db->select('*');
        $this->db->from('tblinvoices');
        $this->db->where('project_id', $relid);
        $invoices = $this->db->get()->result();
        return $invoices;
    }

    /**
     * Added By: Masud
     * Dt: 06/27/2018
     * For Kanban view Contact
     */

    public function get_kanban_invoices($lid = "", $pid = "", $eid = "", $limit = 9, $page = 1, $search = "", $is_kanban = false, $id, $clientid, $data)
    {
        $brandid = get_user_session();
        $aColumns = array(
            'number',
            'total',
            'YEAR(date) as year',
            'date',
            //'tbladdressbook.firstname',
            //'tblstaff.firstname',
            //'tblprojects.name as project_name',
            'duedate',
            'tblinvoices.status',
        );

        $sIndexColumn = "id";
        $sTable = 'tblinvoices';

        $join = array(
            'LEFT JOIN tbladdressbook ON tbladdressbook.addressbookid = tblinvoices.clientid',
            'LEFT JOIN tblcurrencies ON tblcurrencies.id = tblinvoices.currency',
            //'LEFT JOIN tblprojects ON tblprojects.id = tblinvoices.project_id',
            'LEFT JOIN tblstaff ON tblstaff.staffid = tblinvoices.sale_agent',
        );

        $custom_fields = get_table_custom_fields('invoice');

        foreach ($custom_fields as $key => $field) {
            $selectAs = (is_cf_date($field) ? 'date_picker_cvalue_' . $key : 'cvalue_' . $key);

            array_push($customFieldsColumns, $selectAs);
            array_push($aColumns, 'ctable_' . $key . '.value as ' . $selectAs);
            array_push($join, 'LEFT JOIN tblcustomfieldsvalues as ctable_' . $key . ' ON tblinvoices.id = ctable_' . $key . '.relid AND ctable_' . $key . '.fieldto="' . $field['fieldto'] . '" AND ctable_' . $key . '.fieldid=' . $field['id']);
        }

        $where = array();
        array_push($where, ' AND tblinvoices.brandid =' . $brandid);
        if (!empty($pid)) {
            $this->db->select('id');
            $this->db->where('(parent = ' . $pid . ' OR id = ' . $pid . ')');
            $this->db->where('deleted', 0);
            $related_project_ids = $this->db->get('tblprojects')->result_array();
            $related_project_ids = array_column($related_project_ids, 'id');
            if (!empty($related_project_ids)) {
                $related_project_ids = implode(",", $related_project_ids);
                array_push($where, ' AND (tblinvoices.project_id in(' . $related_project_ids . ') OR tblinvoices.eventid in(' . $related_project_ids . '))');
            } else {
                array_push($where, ' AND tblinvoices.project_id =' . $pid);
            }
        } else if (!empty($lid)) {
            array_push($where, ' AND tblinvoices.leadid =' . $lid);
        } else if (!empty($eid)) {
            array_push($where, ' AND tblinvoices.eventid =' . $eid);
        }

        $filter = array();
//Filter by Top Progress bar
        $statuses = $this->invoices_model->get_statuses();
        $statusIds = array();
        foreach ($statuses as $status) {
            if ($this->input->post('invoices_' . $status)) {
                array_push($statusIds, $status);
            }
        }
// Filter by Dropdown value
        if ($this->input->post('status')) {
            $by_status = $this->input->post('status');
            array_push($statusIds, $by_status);
        }

        if (count($statusIds) > 0) {
            array_push($filter, 'AND tblinvoices.status IN (' . implode(', ', $statusIds) . ')');
        }

        if ($this->input->post('invoicedate')) {
            $invoicedate = $this->input->post('invoicedate');
            $invoicedate = explode("-", $invoicedate);
            $invoicestartdate = date("Y-m-d", strtotime($invoicedate[0]));
            $invoiceenddate = date("Y-m-d", strtotime($invoicedate[1]));
            array_push($where, 'AND date(tblinvoices.duedate) between "' . $invoicestartdate . '" AND "' . $invoiceenddate . '"');
        }

        if ($this->input->post('assigned')) {
            array_push($where, 'AND tblinvoices.sale_agent = ' . $this->input->post('assigned'));
        }

        $agents = $this->invoices_model->get_sale_agents();
        $agentsIds = array();
        foreach ($agents as $agent) {
            if ($this->input->post('sale_agent_' . $agent['sale_agent'])) {
                array_push($agentsIds, $agent['sale_agent']);
            }
        }
        if (count($agentsIds) > 0) {
            array_push($filter, 'AND sale_agent IN (' . implode(', ', $agentsIds) . ')');
        }

        $modesIds = array();
        foreach ($data['payment_modes'] as $mode) {
            if ($this->input->post('invoice_payments_by_' . $mode['id'])) {
                array_push($modesIds, $mode['id']);
            }
        }
        if (count($modesIds) > 0) {
            array_push($where, 'AND tblinvoices.id IN (SELECT invoiceid FROM tblinvoicepaymentrecords WHERE paymentmode IN ("' . implode('", "', $modesIds) . '"))');
        }

        $years = $this->invoices_model->get_invoices_years();
        $yearArray = array();
        foreach ($years as $year) {
            if ($this->input->post('year_' . $year['year'])) {
                array_push($yearArray, $year['year']);
            }
        }
        if (count($yearArray) > 0) {
            array_push($where, 'AND YEAR(date) IN (' . implode(', ', $yearArray) . ')');
        }

        if (count($filter) > 0) {
            array_push($where, 'AND (' . prepare_dt_filter($filter) . ')');
        }

        if (is_numeric($clientid)) {
            array_push($where, 'AND tblinvoices.clientid=' . $clientid);
        }
        if (!has_permission('invoices', '', 'view')) {
            array_push($where, 'AND tblinvoices.addedfrom=' . get_staff_user_id());
        }
        if ($is_kanban == true && $limit > 0) {
            $start = ($page - 1) * $limit;
            $this->db->limit($limit, $start);
        }
        $aColumns = do_action('invoices_table_sql_columns', $aColumns);
        $start = "";
        if ($is_kanban == true && $limit > 0) {
            $start = ($page - 1) * $limit;
        }
        $invoicecolumns = array(
            'tblinvoices.id',
            'tblinvoices.clientid',
            'symbol',
            'tblstaff.staffid',
            'tbladdressbook.addressbookid'
        );
        if (!empty($pid)) {
            array_push($invoicecolumns, 'project_id');
        } else if (!empty($lid)) {
            array_push($invoicecolumns, 'leadid');
        } else if (!empty($eid)) {
            array_push($invoicecolumns, 'eventid');
        }
        $result = data_tables_init($aColumns, $sIndexColumn, $sTable, $join, $where, $invoicecolumns, "", $limit, $start);
        return $result['rResult'];
    }

    /**
     * Added By: Masud
     * Dt: 06/21/2018
     * For Pin/Unpin Meeting
     */
    public function pininvoice($invoice_id)
    {
        $session_data = get_session_data();
        $user_id = $session_data['staff_user_id'];

        $pinexist = $this->db->select('pinid')->from('tblpins')->where('pintype = "Invoice" AND pintypeid = ' . $invoice_id . ' AND userid = ' . $user_id)->get()->row();
        if (!empty($pinexist)) {
            $this->db->where('userid', $user_id);
            $this->db->where('pintypeid', $invoice_id);
            $this->db->where('pintype', "Invoice");
            $this->db->delete('tblpins');

            return "deleted";
        } else {
            $this->db->insert('tblpins', array(
                'pintype' => "Invoice",
                'pintypeid' => $invoice_id,
                'userid' => $user_id
            ));

            return "added";
        }
    }

    /**
     * Added By: Masud
     * Dt: 09/07/2018
     * For Gratuity
     */
    function addinvoicegratuity($invoice_id, $data)
    {
        $this->db->where('id', $invoice_id);
        $this->db->update('tblinvoices', $data);
        if ($this->db->affected_rows() > 0) {
            return true;
        }
        return false;
    }

    function updateinvoice($data, $id)
    {
        $this->db->where('id', $id);
        $this->db->update('tblinvoices', $data);
        if ($this->db->affected_rows() > 0) {
            return true;
        }
        return false;
    }
}