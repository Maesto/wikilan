<?php
/**
 * English strings for the wikilan plugin
 */

$lang['menu'] = 'WikiLAN: LAN parties, seats & ports';

// generic
$lang['login_required']   = 'Log in to use this.';
$lang['no_active_lan']    = 'No active LAN right now.';
$lang['error']            = 'Something went wrong.';

// attendance
$lang['attend_btn']       = 'I\'m attending';
$lang['attend_btn_undo']  = 'Not attending after all';
$lang['attendees']        = 'Attendees';
$lang['arrived']          = 'arrived';
$lang['attend_count']     = '%d attending, %d arrived';

// seating
$lang['seat_free']        = 'free';
$lang['seat_reserved']    = 'reserved';
$lang['seat_arrived']     = 'arrived';
$lang['seat_admin_only']  = 'assigned by admins only';
$lang['seat_admin_assigned'] = 'admin-assigned';
$lang['seat_yours']       = 'your seat';
$lang['seat_reserve']     = 'Reserve seat %s';
$lang['seat_release']     = 'Give up seat %s';
$lang['seat_taken']       = 'Seat %s is taken by %s';
$lang['seat_need_attend'] = 'Mark yourself as attending before reserving a seat.';
$lang['seat_table_hdr']   = 'Seat / State / Who';
$lang['wrong_seat']       = 'You reserved seat %s but you\'re plugged in at %s.';
$lang['seat_moved']       = 'You are seated at %s but plugged in at %s.';
$lang['wrong_seat_move']  = 'Move me here';
$lang['admin_seat_warn']  = 'Seat %s is admin-assigned only — please talk to an organizer.';
$lang['seat_have_other'] = 'You already hold seat %s.';
$lang['seat_move_confirm'] = 'You already hold seat %1$s (%2$s). Move to seat %3$s instead?';

// events / calendar
$lang['cal_time']         = 'Time';
$lang['cal_host']         = 'Host';
$lang['cal_no_events']    = 'No events yet.';
$lang['signup_interested'] = 'Interested';
$lang['signup_signedup']  = 'Signed up';
$lang['signup_none']      = 'No thanks';
$lang['signup_counts']    = '%d signed up · %d interested';
$lang['event_price']      = 'Price: %s';
$lang['event_pay_hint']   = 'pay via %1$s — book a transaction of %3$s with the comment %2$s';
$lang['event_pay_product'] = 'pay via %1$s or at the kiosk — buy the product %2$s';
$lang['signup_until']     = 'Sign up until %s.';
$lang['signup_closed']    = 'Signups closed (deadline was %s).';
$lang['signup_closed_err'] = 'Signups for this event are closed.';
$lang['signup_comment_ph'] = 'Comment (e.g. no onions)';
$lang['paid']             = 'paid';
$lang['unpaid']           = 'unpaid';
$lang['mark_paid']        = 'mark paid';
$lang['mark_unpaid']      = 'mark unpaid';
$lang['event_new']        = 'Create event';
$lang['cal_day']          = 'Day %d';
$lang['when_tba']         = '(date to be announced)';

// steam
$lang['steam_fieldset']   = 'Steam';
$lang['steam_link']       = 'Link Steam account';
$lang['steam_unlink']     = 'Unlink Steam';
$lang['steam_linked_as']  = 'Steam linked';
$lang['steam_now_playing'] = 'Now playing';
$lang['steam_sessions']   = 'Played this LAN';
$lang['steam_not_linked'] = 'No Steam account linked.';
$lang['steam_view_profile'] = 'View Steam profile';
$lang['steam_link_why']   = 'Linking shows your Steam name and avatar on the attendee lists and enables now-playing and shared-games features.';
$lang['status_ingame']    = 'In-Game';
$lang['shared_pick']      = 'Pick attendees to compare libraries:';
$lang['shared_compute']   = 'Find shared games';
$lang['shared_none']      = 'No games in common.';
$lang['shared_mponly']    = 'Multiplayer only';
$lang['shared_minplayers'] = 'min. players';

$lang['seat_tag']         = 'Seat %s';

// profile
$lang['profile_lans']     = 'LANs attended';
$lang['profile_seat']     = 'Current seat';
$lang['steam_add_friend'] = 'Add as Steam friend';
$lang['profile_stat_lans']  = 'LANs';
$lang['profile_stat_hours'] = 'hours played';
$lang['profile_stat_games'] = 'games';
$lang['profile_this_lan'] = 'This LAN: %s';
$lang['profile_alltime']  = 'All time';
$lang['profile_no_games'] = 'No tracked games yet.';
$lang['profile_create_title'] = 'Create your profile page';
$lang['profile_create_intro'] = 'No wiki markup needed — everything is optional and can be edited later.';
$lang['profile_f_about']    = 'About me';
$lang['profile_f_clan']     = 'Clan / team';
$lang['profile_f_games']    = 'Favorite games';
$lang['profile_f_hardware'] = 'Hardware';
$lang['profile_create_btn'] = 'Create profile page';
$lang['profile_create_summary'] = 'profile page created';

// strichliste statistics
$lang['sl_title']         = 'Strichliste';
$lang['sl_summary']       = '%d purchases · %s spent';
$lang['sl_balance']       = 'Balance';
$lang['sl_during']        = 'During %s';
$lang['sl_alltime']       = 'All time';
$lang['sl_article']       = 'Article';
$lang['sl_qty']           = 'Qty';
$lang['sl_revenue']       = 'Revenue';
$lang['sl_buyer']         = 'Buyer';
$lang['sl_items']         = 'Items';
$lang['sl_spent']         = 'Spent';
$lang['sl_unavailable']   = 'Strichliste statistics are unavailable right now.';

// notifications
$lang['push_enable']      = 'Enable push notifications';
$lang['push_enabled']     = 'Push notifications on';
$lang['push_unsupported'] = 'Push not supported in this browser';
$lang['notices_title']    = 'Announcements';
$lang['reminder_title']   = 'Starting soon: %s';
$lang['reminder_body']    = '%s starts at %s';
$lang['event_change_title'] = 'Event changed: %s';
