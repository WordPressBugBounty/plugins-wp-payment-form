<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php if ($items) : ?>
    <table class="table wpf_table input_items_table table_bordered" style="width:100%; border:1px solid #cbcbcb; border-collapse:collapse;">
        <tbody>
        <?php foreach ($items as $wppayform_item) : ?>
            <?php if ($showEmpty || (isset($wppayform_item['value']) && $wppayform_item['value'] !== '' && isset($wppayform_item['label']))) : ?>
                <tr>
                    <th style="border-left:1px solid #D6DAE1; padding:10px 14px; text-align:left; vertical-align:top;"><?php echo wp_kses_post($wppayform_item['label']); ?></th>
                    <td style="border-left:1px solid #D6DAE1; padding:10px 14px; vertical-align:top;"><?php
                        if (is_array($wppayform_item['value'])) {
                            echo wp_kses_post(implode(', ', $wppayform_item['value']));
                        } else {
                            echo wp_kses_post($wppayform_item['value']);
                        }; ?>
                    </td>
                </tr>
            <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
