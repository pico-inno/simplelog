<?php


namespace PicoInno\SimpleLog\Enum;

enum LogStatus : string{
    case SUCCESS = "success";
    case WARN = "warn";
    case FAIL = "fail";
}