<?php

function display_acf_repeater_barchasb() {
  // Get the current post or page ID
  $post_id = get_the_ID();
  $field_name = 'pipe_openers'; // Replace with your actual repeater field name

if (empty($field_name) || empty($post_id)) {
  return '';
}

ob_start();

// Check rows exists.
if( have_rows( $field_name, $post_id ) ):
  echo '<div class="pipe-openers-wrapper">';

    // Loop through rows.
    while( have_rows( $field_name, $post_id ) ) : the_row();
    $name = get_sub_field('name'); // Replace with another sub field name if needed
    $expert = get_sub_field('expert');
    $trust = get_sub_field('trust');
    $credit = get_sub_field('credit');
    $image = get_sub_field('image');
    $projects_done = get_sub_field('projects_done');
    $experience = get_sub_field('experience');
    $services_coverage = get_sub_field('services_coverage');
    $project_situation = get_sub_field('project_situation');
    $arrival_time = get_sub_field('arrival_time');
    $phone = get_sub_field('phone');

    if ($image) {
      $image_url = $image['url'];
      $image_alt = $image['alt'];
    }
    ?>

    <div class="pipe-opener">
      <div class="pipe-opener__first-row">
        <div class="pipe-opener__first-row-col-1">
          <div class="pipe-opener__name"><?php echo $name  ; ?></div>
          <div class="pipe-opener__expert"><?php echo $expert ; ?></div>
          <div class="pipe-opener__values">
            <div class="pipe-opener__trust"><?php echo $trust ; ?></div>
            <div class="pipe-opener__credit"><?php echo $credit ; ?></div>
          </div>
        </div>
        <div class="pipe-opener__first-row-col-2">
          <div class="pipe-opener__image">
            <?php
              echo '<img src="' . esc_url($image_url) . '" alt="' . esc_attr($image_alt) . '">';
            ?>
          </div>
        </div>
      </div>

      <div class="pipe-opener__second-row">
        <div class="pipe-opener__second-row-col-1">
          <ul class="pipe-openers-list">
            <li class="pipe-openers-list-item-works"><?php echo $projects_done ; ?></li>
            <li class="pipe-openers-list-item-experience"><?php echo $experience ; ?></li>
          </ul>
        </div>
        <div class="pipe-opener__second-row-col-2">
          <ul class="pipe-openers-list">
            <li class="pipe-openers-list-item-coverage"><?php echo $services_coverage ; ?></li>
            <li class="pipe-openers-list-item-work-situation"><?php echo $project_situation ; ?></li>
          </ul>
        </div>
      </div>

      <div class="pipe-opener__third-row">
        <div class="pipe-opener__third-row-col-1">
          <div class="pipe-openers-time-arrival"><?php echo $arrival_time ; ?></div>
        </div>
        <div class="pipe-opener__third-row-col-2">
          <div class="pipe-openers-phone">
              <a href="tel:<?php echo $phone; ?>"><?php echo $phone; ?></a>
          </div>
          
        </div>
      </div>
    </div>
    <?php
    // End loop.
    endwhile;
  echo '</div>';
// No value.
else :
    echo '';
endif;

return ob_get_clean();

}

add_shortcode('acf_repeater_barchasb', 'display_acf_repeater_barchasb');