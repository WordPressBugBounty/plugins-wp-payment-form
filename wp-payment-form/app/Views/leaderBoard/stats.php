
<div class="wpf_leaderboard_stats">
    <div class="wpf_leaderboard_stats_content">
        <div class="wpf_leaderboard_stats_raised">
            <p class="wpf_raised_amount"><?php echo esc_html($raised) ?></p>
            <h4>Raised</h4>
        </div>
        <div class="wpf_leaderboard_stats_count">
            <p class="wpf_count_amount"><?php echo esc_html($total_donations) ?></p>
            <h4>Donations</h4>
        </div>
        <div class="wpf_leaderboard_stats_goal">
            <p class="wpf_donation_goal"><?php echo esc_html($goal) ?></p>
            <h4>Goal</h4>
        </div>
    </div>
    <div class="wpf_donation_percent">
        <p style="width: <?php echo esc_attr($percent); ?>%;">
            <?php echo esc_html($percent) . '%'; ?>
        </p>
    </div>

</div>
