<?php
namespace WPWE;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin bootstrap coordinator.
 *
 * Wires together the core components and the integration manager on
 * plugins_loaded. Keeping this logic in a dedicated class (rather than
 * inline closures in the main plugin file) makes unit testing possible
 * without triggering live WP hooks.
 */
class Core {

    /**
     * Initialise all plugin components.
     *
     * Called on the plugins_loaded action so that all plugins are available
     * for integration detection.
     */
    public static function init(): void {
        // Ensure DB schema is current (no-op when already on latest version).
        Database::install();

        // Core feature registration.
        ( new Admin_Menu() )->register();
        ( new Form_Renderer() )->register();
        ( new Entry_Handler() )->register();

        // Third-party integrations – loaded only when the host plugin is detected.
        Integration_Manager::register();
    }
}
