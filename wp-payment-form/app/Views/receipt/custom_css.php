<style type="text/css">
    .wpf_payment_receipt{
        display: block !important;
    }
    /* .wpf_table {
        width: 100%;
        empty-cells: show;
        font-size: 14px;
        border: 1px solid #cbcbcb !important;
    } */

    .wpf_order_items_table {
        width: 100%;
        border-collapse: collapse;
        border-radius: 10px;
    }

    .wpf_table td, .wpf_table th {
        border-left: 1px solid #D6DAE1;
        border-width: 0 0 0 1px;
        font-size: 15px;
        margin: 0;
        overflow: visible;
        padding: .5em 1em;
        font-family: Times New Roman;
    }

    .wpf_table td:first-child, .wpf_table th:first-child {
        border-left-width: 0
    }

    .wpf_table thead {
        background-color: #F5F6F7;
        color: #000;
        /* text-align: left; */
        vertical-align: bottom
    }

    .wpf_table td {
        background-color: transparent
    }

    /* .wpf_table tbody {
        border-top: 1px solid #D6DAE1;
    } */

    .wpf_table tfoot {
        border-top: 1px solid #D6DAE1;
    }

    table.input_items_table {
        width: 100%;
        border-collapse: collapse;
        border-radius: 4px;
    }

    .table.input_items_table tr {
        border-bottom: 1px solid #D6DAE1;
    }

    .table.input_items_table tr:last-child {
        border-bottom: none;
    }

    table.input_items_table tr td, table.input_items_table tr th {
        border-left: 1px solid #D6DAE1;
        text-align: left;
        width: auto;
        word-break: normal;
    }

    table.input_items_table tr th {
        min-width: 35%;
    }

    .wpf_payment_info {
        display: flex;
        justify-content: flex-start;
        align-items: center;
        width: 100%;
        -webkit-box-shadow: 0px -2px #e3e8ee;
        box-shadow: 0px -2px #e3e8ee;
        background-color: rgb(247, 250, 252);
        color: rgb(56, 56, 56);
    }

    .wpf_payment_info_item {
        display: inline-block;
        margin-right: 0px;
        -webkit-box-shadow: inset -1px 0 #e3e8ee;
        box-shadow: inset -1px 0 #e3e8ee;
        padding: 10px 30px;
        font-family: Times New Roman;
        font-size: 15px;
    }

    .wpf_payment_info_item:last-child {
        box-shadow: none;
    }

    .wpf_payment_info_item .wpf_item_heading {
        /* font-size: 14px; */
        font-weight: bold;
        padding: 5px 0;
    }

    .wpf_payment_info_item .wpf_item_value {
        font-size: 14px;
    }

    .wpf_payment_receipt h4 {
        font-size: 18px;
        font-family: Times New Roman;
	    font-weight: 600;
        margin: 0;
        padding: 30px 0 12px 0;
    }

    .wpf_item_content.paid {
        display: flex;
        gap: 6px;
        color: #16896B;
        background: #F3FAF8;
        border: 1px solid #F3FAF8;
    }

    .wpf_order_items_table {
        border-collapse: collapse;
    }

    .wpf_order_items_table tr {
        border-bottom: 1px solid #D6DAE1;
        text-align: center;
    }

    .wpf_order_items_table tbody tr {
        border-top: 1px solid #D6DAE1;
    }

    .wpf_order_items_table tr:last-child {
        border-bottom: none;
    }

    .wpf_order_items_table_wrapper {
        border: 1px solid #D6DAE1;
        border-radius: 4px;
        overflow: hidden;
    }

    .wpf_submission_details {
        border: 1px solid #D6DAE1;
        border-radius: 4px;
        overflow: hidden;
        padding-bottom: 0 !important;
    }
</style>