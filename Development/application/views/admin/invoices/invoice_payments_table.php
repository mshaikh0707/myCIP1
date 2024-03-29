<div class="row">
<div class="col-md-12">
<div class="table-responsive">
    <table class="table table-hover">
        <thead>
        <tr>
            <th><?php echo _l('payments_table_number_heading'); ?></th>
            <th><?php echo _l('payments_table_mode_heading'); ?></th>
            <th><?php echo _l('payments_table_date_heading'); ?></th>
            <th><?php echo _l('Gratuity'); ?></th>
            <th><?php echo _l('payments_table_amount_heading'); ?></th>
            <th><?php echo _l('options'); ?></th>
        </tr>
        </thead>
        <tbody>
        <?php

        foreach($invoice->payments as $payment){ ?>
            <tr class="payment">
                <td><?php echo $payment['paymentid']; ?> </td>
                <td><?php echo $payment['name']; ?>
                    <?php if(!empty($payment['paymentmethod'])){
                        echo ' - ' . $payment['paymentmethod'];
                    }
                    if($payment['transactionid']){
                        echo '<br />'._l('payments_table_transaction_id',$payment['transactionid']);
                    }
                    ?>

                </td>
                <td><?php echo _d($payment['date']); ?></td>
                <td><?php echo $payment['gratuity_val']."(".$payment['gratuity_percent']."%)"; ?></td>
                <td><?php echo format_money($payment['amount'],$invoice->symbol); ?></td>
                <td>
                    <!-- <a href="<?php echo admin_url('payments/payment/'.$payment['paymentid']); ?>" class="btn  btn-icon"><i class="fa fa-pencil-square-o"></i></a>
                    <?php if(has_permission('payments','','delete')){ ?>
                    <a href="<?php echo admin_url('invoices/delete_payment/'.$payment['paymentid'] . '/' . $payment['invoiceid']); ?>" class="btn btn-icon _delete"><i class="fa fa-remove"></i></a>
                    <?php } ?> -->
                    <?php echo icon_btn('payments/pdf/' . $payment['paymentid'], 'file-pdf-o','btn-icon '); ?>
                </td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
</div>
</div>
</div>