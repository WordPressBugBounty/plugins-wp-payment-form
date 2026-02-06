<?php if ($items) : ?>
    <table class="table wpf_table input_items_table table_bordered">
        <tbody>
        <?php foreach ($items as $wppayform_item) : ?>
            <?php if ($showEmpty || (isset($wppayform_item['value']) && $wppayform_item['value'] !== '' && isset($wppayform_item['label']))) : ?>
                <tr>
                    <th><?php echo wp_kses_post($wppayform_item['label']); ?></th>
                    <td><?php
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
