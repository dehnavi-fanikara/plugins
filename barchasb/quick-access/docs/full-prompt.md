 Full Prompt for Agent: WordPress Plugin Development (Quick Access System)
Context:

Build a robust, high-performance WordPress plugin named “FK-QuickAccess” that renders a customizable interactive grid via a shortcode. The output must mimic an Elementor-style “Icon Box” structure but remain independent of Elementor’s heavy footprint.

Technical Requirements & Stack:

Backend: PHP 8.2+ (OOP approach), WordPress Plugin API.
Frontend: Vanilla CSS (for speed), Vanilla JS (No jQuery if possible) for smooth scrolling.
Architecture: Admin Settings Page (using Settings API) to manage 4 global items + Shortcode attributes for overrides.
Detailed Functional Specifications:

1. Data Structure & Admin Panel:

Create a clean admin menu to manage 4 “Quick Access” slots.
Each slot must have:
SVG Icon: A textarea for raw SVG code (ensure sanitization while allowing SVG tags).
Title: Text field.
Description: Text field.
Target ID: The #ID to which the button should scroll (Smooth Scroll).
Styling Controls: Individual color pickers for Icon, Title, and Description.
2. Layout & Responsive Design (UI/UX):

Desktop: 4-column grid (display: grid or flex).
Mobile: 2-column grid.
Aesthetics:
Border-radius: 8px.
Interactive State: Subtle hover effect (e.g., scale 1.02 or box-shadow transition).
Consistency: Use CSS variables to ensure the design inherits site-wide font families.
3. Behavior & Smooth Scrolling:

Implement a lightweight JavaScript handler for smooth scrolling to the Target ID.
Ensure offset calculation (e.g., if the site has a sticky header, the scroll should stop 80px above the target).
4. Advanced Features (Shortcode Attributes):

Sticky Mode: Add an attribute sticky="true|false". If true, the entire Quick Access container should stick to the top of the viewport on scroll (position: sticky; top: 0; z-index: 999;).
Scroll-to-Top (Back-to-QuickAccess): Add an attribute show_back_btn="true". If enabled, render a floating/fixed button that appears only after scrolling past the section, leading the user back to the Quick Access section or Page Top.
5. Performance & Security (Developer Focus):

Non-blocking: Enqueue styles and scripts only when the shortcode is present on the page.
Sanitization: Use wp_kses for SVG outputs to prevent XSS while maintaining path integrity.
Idempotency: Ensure the shortcode can be called multiple times on one page without ID conflicts.
6. Implementation Logic (Boilerplate Structure):

class FK_Quick_Access_Plugin
Methods: register_settings(), render_shortcode($atts), enqueue_assets().
Shortcode: [quick_access sticky="true" show_back_btn="true"].
UI/UX Considerations:

Ensure high contrast ratios for accessibility.
The SVG should be responsive (use width="100%" and height="auto" within a fixed-size container).
Add a “Copy Shortcode” button in the admin panel for ease of use.