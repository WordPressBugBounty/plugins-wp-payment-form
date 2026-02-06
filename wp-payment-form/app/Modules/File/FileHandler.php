<?php

namespace WPPayForm\App\Modules\File;

class FileHandler
{
    private $originalFile;
    private $validations;
    private $file;

    public function __construct($file, $validations = [])
    {
        $this->originalFile = $file;
        $this->validations = $validations;
        $this->file = new File($file['tmp_name'], $file['name']);
    }

    public function validate($rules)
    {
        $errors = [];
        foreach ($rules as $ruleName => $ruleValue) {
            if ($ruleName == 'extensions') {
                $fileExtension = $this->file->guessExtension();
                if(in_array('.mp3', $ruleValue)) {
                    $ruleValue[] = '.mpga';
                }
                if (!in_array('.' . $fileExtension, $ruleValue)) {
                    $errors[$ruleName] = __('Invalid File Extension', 'wp-payment-form');
                }
            } else if ($ruleName == 'max_file_size' && $ruleValue) {
                $valueInBytes = $ruleValue * 1024 * 1024;
                if ($this->file->getSize() > $valueInBytes) {
                    $errors[$ruleName] = __('File size needs to be less than ', 'wp-payment-form') .$ruleValue. 'MB';
                }
            }
        }
        return $errors;
    }

    public function upload()
    {
        $uploadedFile = wp_handle_upload(
            $this->originalFile,
            ['test_form' => false]
        );
        return $uploadedFile;
    }

    /**
     * Register filters for custom upload dir
     */
    public function overrideUploadDir()
    {
        add_filter('wp_handle_upload_prefilter', function ($file) {
            add_filter('upload_dir', [$this, 'setCustomUploadDir']);

            add_filter('wp_handle_upload', function ($fileinfo) {
                remove_filter('upload_dir', [$this, 'setCustomUploadDir']);
                $fileinfo['file'] = basename($fileinfo['file']);
                return $fileinfo;
            });

            return $this->renameFileName($file);
        });
    }

    /**
     * Set plugin's custom upload dir
     * @param  array $param
     * @return array $param
     */

    public function setCustomUploadDir($param)
{
    if (!function_exists('WP_Filesystem')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    global $wp_filesystem;

    if (empty($wp_filesystem)) {
        if (!WP_Filesystem()) {
            wp_send_json_error(['message' => 'Unable to initialize filesystem'], 500);
            return $param;
        }
    }

    $param['path'] = trailingslashit($param['basedir']) . trim(WPPAYFORM_UPLOAD_DIR, '/');
    $param['url']  = trailingslashit($param['baseurl']) . trim(WPPAYFORM_UPLOAD_DIR, '/');

    if (!$wp_filesystem->is_dir($param['path'])) {
        if (!$wp_filesystem->mkdir($param['path'], FS_CHMOD_DIR)) {
            wp_send_json_error(['message' => 'Failed to create upload directory'], 500);
            return $param;
        }

        $source_htaccess = __DIR__ . '/Stubs/htaccess.stub';
        $destination_htaccess = trailingslashit($param['path']) . '.htaccess';

        if ($wp_filesystem->exists($source_htaccess)) {
            $contents = $wp_filesystem->get_contents($source_htaccess);
            if ($contents !== false) {
                $wp_filesystem->put_contents($destination_htaccess, $contents, FS_CHMOD_FILE);
            }
        }
    }

    return $param;
}



    /**
     * Rename the uploaded file name before saving
     * @param  array $file
     * @return array $file
     */
    public function renameFileName($file)
    {
        $prefix = 'wpf-' . md5(uniqid(wp_rand())) . '-wpf-';
        $file['name'] = $prefix . $file['name'];
        return $file;
    }

}
