<?php
namespace WPPayForm\App\Modules\Builder;
use WPPayForm\Framework\Support\Arr;
use WPPayForm\App\Models\DemoForms;



class RenderDemoForm {
    public function renderForm($form_id) {
        $forms = DemoForms::demoForms();
        $form =  Arr::get($forms, $form_id);
        $elements = json_decode(Arr::get($form, 'data'), true);
        $elements = Arr::get($elements, 'form_meta.wppayform_paymentform_builder_settings');
        $form["ID"] = $form_id;
        $form["asteriskPosition"] = "left";
        $form["post_name"] = $form_id;
        $form = (object) $form;
        (new Render())->registerTemplatePreviewScripts($form);
        ob_start();

        if ($elements) {
            ?>
                <div class="wpf_form_wrapper wpf_form_wrapper_4400">
                    <form data-wpf_form_id="4400" wpf_form_instance="wpf_form_instance_4400_1" class="wpf_form wpf_form_instance_4400_1 wpf_strip_default_style wpf_form_id_4400 wpf_label_top wpf_asterisk_right wpf_submit_button_pos_left wppayform_has_payment wpf_default_form_styles" method="POST" action="https://wordpress.test" id="wpf_form_id_4400">
                    <?php
                    $isStepForm = $this->checkIsStepForm($elements);
                    if (!empty($isStepForm)) {

                        $class = '';
                        if (count($isStepForm['editor_elements']['form_steps']) > 6) {
                            $class = 'justify-start';
                        }
                        ?> <div id="wpf_svg_wrap">
                        <div class="step-form <?php echo esc_attr($class); ?>">
                            <?php
                            foreach ($isStepForm['editor_elements']['form_steps'] as $key => $step) {
                            ?>
                                <div id="<?php echo intval($key) + 1; ?>" class="step-form-item">
                                    <div class="step-form-item-header">
                                        <span id="<?php echo intval($key) + 1; ?>" class="number wpf-step-header-btn"><?php echo intval($key) + 1; ?></span>
                                    </div>
                                    <div class="step-form-item-content">
                                        <h2><?php echo esc_html($step['title']); ?></h2>
                                        <p><?php echo esc_textarea($step['description']); ?></p>
                                    </div>
                                </div>
                            <?php
                            }
                            ?>
                        </div>
                        <?php
                        $step_elements = $this->sortElemntsByActivePage($elements);
                        foreach ($isStepForm['editor_elements']['form_steps'] as $key => $elements) {
                            $className = "wpf_step_section_" . ($key + 1);
                        ?>
                            <section class="wpf_step_section" id="<?php echo esc_attr($className); ?>">
                                <?php
                                if (!empty($step_elements[$key])) {
                                    foreach (Arr::get($step_elements, $key) as $element) {
                                        if ($element['type'] != 'step_form') {
                                            do_action('wppayform/render_component_' . $element['type'], $element, $form, $elements);
                                        }
                                    }
                                }
                                // check if this is last page
                                // if (sizeof($isStepForm['editor_elements']['form_steps']) == $key + 1) {
                                //     $this->renderFormFooter($form, empty($isStepForm));
                                // }
                                if (sizeof($isStepForm['editor_elements']['form_steps']) == $key + 1) {
                                ?>
                                    <button class="wpf_step_button" id="wpf_step_prev">&larr; <?php echo esc_html__('Previous', 'wp-payment-form') ?></button>
                                    <button class="wpf_step_button" id="wpf_step_next"><?php echo esc_html__('Next', 'wp-payment-form') ?> &rarr;</button>
                                    <div style="display: none" class="wpf_form_notices"></div> <?php
                                }
                                ?>
                            </section>
                        <?php
                        }
                        ?>
                    </div>
                    </form>
                </div>
                <?php
            } else {
                foreach ($elements as $element) {
                    do_action('wppayform/render_component_' . $element['type'], $element, $form, $elements);
                }
            }
            $form_body = ob_get_clean();
            (new Render())->addAssetsForPreview($form);
            return $form_body;
        } else {
            return "<p style='color:red;font-size: 16px;'>Notice: Please add some fields on ($form->post_title)</p>";
        }
    }

    private function checkIsStepForm($elements)
    {
        foreach ($elements as $element) {
            if ($element['type'] == 'step_form') {
                return $element;
            }
        }
        return [];
    }

    private function sortElemntsByActivePage($elements)
    {
        $sortedElements = [];
        foreach ($elements as $element) {
            $sortedElements[$element['active_page']][] = $element;
        }
        return $sortedElements;
    }
}