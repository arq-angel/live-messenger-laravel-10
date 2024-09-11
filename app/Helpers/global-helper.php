<?php

if (!function_exists("timeAgo")) {
    function timeAgo($timeStamp) {
        $timeDifference = time() - strtotime($timeStamp);
        $seconds = $timeDifference;
        $minutes = round($seconds / 60);
        $hours = round($seconds / 3600);
        $days = round($seconds / 86400);

        if ($seconds < 60) {
            if ($seconds <= 1) {
                return "a second ago";
            }
            return $seconds."s ago";
        } elseif ($minutes < 60) {
            return $minutes."m ago";
        } elseif ($hours < 24) {
            return $hours."h ago";
        } else {
            return date("j M y", strtotime($timeStamp));
        }
    }
}

