<?php
namespace WCQA;

class ReportStore {
  const OPT = 'wcqa_last_result';

  public static function save_last(array $data): void {
    update_option(self::OPT, $data, false);
  }

  public static function get_last(): ?array {
    $d = get_option(self::OPT, null);
    return is_array($d) ? $d : null;
  }
}
