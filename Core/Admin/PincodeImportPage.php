<?php

/**
 * Admin Page: Pincode Import
 * Allows administrators to upload a CSV file to populate the pincode map table.
 */
class PincodeImportPage {

    /**
     * Register the admin menu page.
     */
    public static function register() {
        add_menu_page(
            'Pincode Import',
            'Pincode Import',
            'manage_options',
            'zh-pincode-import',
            [self::class, 'renderPage'],
            'dashicons-database-import',
            80
        );
    }

    /**
     * Render the admin page content.
     */
    public static function renderPage() {
        ?>
        <div class="wrap">
            <h1>ZeroHold Pincode Import</h1>

            <form method="post" enctype="multipart/form-data">
                <?php wp_nonce_field('zh_pincode_import'); ?>

                <p>Upload a CSV file to update the pincode database. Correct CSV format is required.</p>
                <input type="file" name="pincode_csv" accept=".csv" required />
                <br><br>
                <input type="submit" value="Import CSV" class="button button-primary" />

            </form>

            <?php self::handleUpload(); ?>
        </div>
        <?php
    }

    /**
     * Handle the file upload and trigger import.
     */
    private static function handleUpload() {
        if (!isset($_FILES['pincode_csv'])) return;

        if (!wp_verify_nonce($_POST['_wpnonce'], 'zh_pincode_import')) {
            echo "<div class='notice notice-error'><p>Security check failed.</p></div>";
            return;
        }

        $file = $_FILES['pincode_csv']['tmp_name'];

        if (!file_exists($file)) {
            echo "<div class='notice notice-error'><p>Upload failed.</p></div>";
            return;
        }

        self::importCSV($file);

        echo "<div class='notice notice-success'><p>Pincode CSV Imported Successfully.</p></div>";
    }

    /**
     * Process the CSV file and insert/replace records in the database.
     *
     * @param string $file Path to the temporary uploaded file.
     */
    private static function importCSV($file) {
        global $wpdb;
        $table = $wpdb->prefix . 'zh_pincode_map';

        $handle = fopen($file, 'r');
        if (!$handle) return;

        // skip header row
        fgetcsv($handle);

        while (($data = fgetcsv($handle)) !== false) {
            // Expected indices based on user request:
            // 0: Circle, 1: Region, 7: District, 8: State, 4: Pincode
            $circle   = $data[0] ?? '';
            $region   = $data[1] ?? '';
            $district = $data[7] ?? '';
            $state    = $data[8] ?? '';
            $pincode  = $data[4] ?? '';

            if (!$pincode) continue;

            $wpdb->replace($table, [
                'pincode' => $pincode,
                'state'   => $state,
                'district'=> $district,
                'circle'  => $circle,
                'region'  => $region
            ]);
        }

        fclose($handle);
    }
}
