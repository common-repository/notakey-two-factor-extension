jQuery(document).ready(function () {
    jQuery('#login_error').hide();

    function ntkShowStatus(status_id, show_resend) {
        var stats = ['#ntk_auth_wait', '#ntk_auth_pending', '#ntk_auth_error', '#ntk_auth_timeout', '#ntk_auth_success', '#ntk_auth_denied'];

        for (var st of stats) {
            if (st == status_id) {
                jQuery(st).show();
                continue;
            }

            jQuery(st).hide();
        }

        if (show_resend) {
            jQuery("#ntk_resend_auth").show();
        } else {
            jQuery("#ntk_resend_auth").hide();
        }
    }

    function ntkWaitForAuth() {
        wp.ajax.post("ntk_check_auth_status", {
            uuid: jQuery('#wp-auth-ntk-uuid').val()
        })
            .done(function (response) {
                if (response == 0) {
                    // error state
                    ntkShowStatus('#ntk_auth_error', true);
                }

                if (response == 1) {
                    // we have one pending
                    ntkShowStatus('#ntk_auth_pending');
                    setTimeout(ntkWaitForAuth(), 1000);
                }

                if (response == 2) {
                    // timeout
                    ntkShowStatus('#ntk_auth_timeout', true);
                }

                if (response == 3) {
                    // great success
                    setTimeout(function () {
                        try {
                            jQuery('#loginform').submit();
                        } catch (e) { }
                    }, 200);
                    ntkShowStatus('#ntk_auth_success');
                }

                if (response == 4) {
                    // denied by user
                    ntkShowStatus('#ntk_auth_denied');
                }
            });
    };

    ntkWaitForAuth();
});