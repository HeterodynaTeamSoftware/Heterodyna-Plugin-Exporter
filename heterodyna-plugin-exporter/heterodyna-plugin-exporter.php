<?php
/**
 * Plugin Name: Heterodyna Plugin Exporter
 * Description: Eksportuje kod zainstalowanej wtyczki WordPress do gotowego pliku ZIP.
 * Version: 1.0.0
 * Author: Heterodyna Team Software
 * Author URI: https://heterodynateamsoftware.pl
 * License: GPL-2.0-or-later
 * Text Domain: heterodyna-plugin-exporter
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Heterodyna_Plugin_Exporter {
    private const PAGE_SLUG = 'heterodyna-plugin-exporter';
    private const NONCE_ACTION = 'hpe_export_plugin';

    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'register_menu']);
        add_action('admin_post_hpe_export_plugin', [__CLASS__, 'handle_export']);
    }

    public static function register_menu(): void {
        add_management_page(
            'Heterodyna Plugin Exporter',
            'Heterodyna Plugin Exporter',
            'manage_options',
            self::PAGE_SLUG,
            [__CLASS__, 'render_page']
        );
    }

    public static function render_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Brak uprawnień.');
        }

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = get_plugins();
        uasort($plugins, static function (array $a, array $b): int {
            return strcasecmp((string) ($a['Name'] ?? ''), (string) ($b['Name'] ?? ''));
        });
        ?>
        <div class="wrap">
            <h1>Heterodyna Plugin Exporter</h1>
            <p>Wybierz wtyczkę, a WordPress spakuje jej aktualny kod z serwera do pliku ZIP.</p>

            <?php if (!class_exists('ZipArchive')) : ?>
                <div class="notice notice-error"><p><strong>Brak PHP ZipArchive.</strong> Hosting musi mieć włączone rozszerzenie <code>zip</code>.</p></div>
            <?php endif; ?>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Wtyczka</th>
                        <th>Wersja</th>
                        <th>Plik główny</th>
                        <th>Eksport</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($plugins as $plugin_file => $data) :
                    $url = wp_nonce_url(
                        admin_url('admin-post.php?action=hpe_export_plugin&plugin=' . rawurlencode($plugin_file)),
                        self::NONCE_ACTION . '|' . $plugin_file
                    );
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html($data['Name'] ?: $plugin_file); ?></strong></td>
                        <td><?php echo esc_html($data['Version'] ?: '—'); ?></td>
                        <td><code><?php echo esc_html($plugin_file); ?></code></td>
                        <td><a class="button button-primary" href="<?php echo esc_url($url); ?>">Pobierz ZIP</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <p style="margin-top:16px;color:#646970;">Eksport nie modyfikuje wybranej wtyczki. Działa wyłącznie dla administratora.</p>
        </div>
        <?php
    }

    public static function handle_export(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Brak uprawnień.', 403);
        }

        if (!class_exists('ZipArchive')) {
            wp_die('Na serwerze nie jest dostępne rozszerzenie PHP ZipArchive.', 500);
        }

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugin_file = isset($_GET['plugin']) ? sanitize_text_field(wp_unslash($_GET['plugin'])) : '';
        if ($plugin_file === '') {
            wp_die('Nie wskazano wtyczki.', 400);
        }

        check_admin_referer(self::NONCE_ACTION . '|' . $plugin_file);

        $plugins = get_plugins();
        if (!isset($plugins[$plugin_file])) {
            wp_die('Wybrana wtyczka nie istnieje.', 404);
        }

        $plugins_root = realpath(WP_PLUGIN_DIR);
        $main_file = realpath(WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . $plugin_file);

        if ($plugins_root === false || $main_file === false || !is_file($main_file)) {
            wp_die('Nie można odnaleźć plików wtyczki.', 404);
        }

        $plugins_root_n = trailingslashit(wp_normalize_path($plugins_root));
        $main_file_n = wp_normalize_path($main_file);
        if (strpos($main_file_n, $plugins_root_n) !== 0) {
            wp_die('Nieprawidłowa ścieżka wtyczki.', 400);
        }

        $relative_dir = dirname($plugin_file);
        $single_file = ($relative_dir === '.');

        if ($single_file) {
            $source = $main_file;
            $archive_root = sanitize_file_name(pathinfo($plugin_file, PATHINFO_FILENAME));
        } else {
            $source = realpath(WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . $relative_dir);
            if ($source === false || !is_dir($source)) {
                wp_die('Nie można odnaleźć katalogu wtyczki.', 404);
            }

            $source_n = trailingslashit(wp_normalize_path($source));
            if (strpos($source_n, $plugins_root_n) !== 0) {
                wp_die('Nieprawidłowa ścieżka katalogu wtyczki.', 400);
            }

            $archive_root = sanitize_file_name(basename($source));
        }

        if ($archive_root === '') {
            $archive_root = 'wordpress-plugin';
        }

        $version = sanitize_file_name((string) ($plugins[$plugin_file]['Version'] ?? ''));
        $download_name = $archive_root . ($version !== '' ? '-v' . $version : '') . '.zip';

        $temp_file = tempnam(get_temp_dir(), 'hpe-');
        if ($temp_file === false) {
            wp_die('Nie udało się utworzyć pliku tymczasowego.', 500);
        }

        $zip = new ZipArchive();
        $result = $zip->open($temp_file, ZipArchive::OVERWRITE);
        if ($result !== true) {
            @unlink($temp_file);
            wp_die('Nie udało się utworzyć ZIP. Kod błędu: ' . intval($result), 500);
        }

        try {
            if ($single_file) {
                if (!$zip->addFile($source, basename($source))) {
                    throw new RuntimeException('Nie udało się dodać pliku wtyczki do ZIP.');
                }
            } else {
                self::add_directory_to_zip($zip, $source, $archive_root);
            }

            if (!$zip->close()) {
                throw new RuntimeException('Nie udało się zamknąć archiwum ZIP.');
            }

            if (!is_file($temp_file) || filesize($temp_file) === 0) {
                throw new RuntimeException('Utworzone archiwum ZIP jest puste.');
            }

            nocache_headers();
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . str_replace('"', '', $download_name) . '"');
            header('Content-Length: ' . filesize($temp_file));
            header('X-Content-Type-Options: nosniff');

            $fp = fopen($temp_file, 'rb');
            if ($fp === false) {
                throw new RuntimeException('Nie udało się otworzyć ZIP do pobrania.');
            }

            while (!feof($fp)) {
                $chunk = fread($fp, 1024 * 1024);
                if ($chunk === false) {
                    fclose($fp);
                    throw new RuntimeException('Błąd odczytu ZIP.');
                }
                echo $chunk;
                flush();
            }

            fclose($fp);
            @unlink($temp_file);
            exit;
        } catch (Throwable $e) {
            if ($zip->status === ZipArchive::ER_OK) {
                $zip->close();
            }
            @unlink($temp_file);
            wp_die('Eksport nie powiódł się: ' . esc_html($e->getMessage()), 500);
        }
    }

    private static function add_directory_to_zip(ZipArchive $zip, string $source_dir, string $archive_root): void {
        $source_real = realpath($source_dir);
        if ($source_real === false) {
            throw new RuntimeException('Nieprawidłowy katalog źródłowy.');
        }

        $source_n = trailingslashit(wp_normalize_path($source_real));
        $zip->addEmptyDir($archive_root);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source_real, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isLink()) {
                continue;
            }

            $item_real = realpath($item->getPathname());
            if ($item_real === false) {
                continue;
            }

            $item_n = wp_normalize_path($item_real);
            if (strpos($item_n, $source_n) !== 0) {
                continue;
            }

            $relative = ltrim(substr($item_n, strlen($source_n)), '/');
            if ($relative === '') {
                continue;
            }

            $zip_path = $archive_root . '/' . $relative;

            if ($item->isDir()) {
                $zip->addEmptyDir($zip_path);
            } elseif ($item->isFile() && !$zip->addFile($item_real, $zip_path)) {
                throw new RuntimeException('Nie udało się dodać pliku: ' . $relative);
            }
        }
    }
}

Heterodyna_Plugin_Exporter::init();
