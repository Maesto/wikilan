<?php

use dokuwiki\plugin\struct\meta\Value;

/**
 * Bureaucracy field type `wikilan_day`: the event.day struct field as a
 * dropdown of fahrplan day numbers, each labelled with the computed
 * weekday/date from the edition schedule (so "2" reads as "2 · Saturday
 * 17.10."). Range: one day before buildup through the end/teardown day.
 * Extends struct's bureaucracy field so struct's save hook stores it.
 *
 * Form syntax:  wikilan_day "=1"
 */
class helper_plugin_wikilan_day extends helper_plugin_struct_field
{
    public function initialize($args)
    {
        // default column is event.day; an explicit schema.field first argument
        // reuses the dropdown for other day columns (e.g. event.cutoffday)
        if (!isset($args[1]) || strpos($args[1], '.') === false) {
            array_splice($args, 1, 0, ['event.day']);
        }
        parent::initialize($args);
    }

    /** Same wrapper markup as struct_field, but a <select> as the editor */
    protected function makeField(Value $field, $name)
    {
        $trans = hsc($field->getColumn()->getTranslatedLabel());
        $hint = hsc($field->getColumn()->getTranslatedHint());
        $class = $hint ? 'hashint' : '';
        $lclass = $this->error ? 'bureaucracy_error' : '';
        $colname = $field->getColumn()->getFullQualifiedLabel();
        $required = empty($this->opt['optional']) ? ' <sup>*</sup>' : '';

        $id = uniqid('struct__', true);
        $input = $this->daySelect($field, $name, $id);

        $html = '<div class="field">';
        $html .= "<label class=\"$lclass\" data-column=\"$colname\" for=\"$id\">";
        $html .= "<span class=\"label $class\" title=\"$hint\">$trans$required</span>";
        $html .= '</label>';
        $html .= "<span class=\"input\">$input</span>";
        $html .= '</div>';

        return $html;
    }

    protected function daySelect(Value $field, string $name, string $id): string
    {
        global $ID;
        /** @var helper_plugin_wikilan $wl */
        $wl = plugin_load('helper', 'wikilan');

        $cur = $field->getRawValue();
        if (is_array($cur)) $cur = reset($cur);
        $cur = trim((string)$cur);

        $lan = $wl->contextLan([], $ID);
        $dates = $lan ? $wl->lanDates($lan) : ['buildup' => null, 'start' => null, 'end' => null];
        $start = $dates['start'];

        if ($start) {
            // one spare day before buildup for early arrivals
            $first = min($wl->dayNumber($dates['buildup'] ?? $start, $start), 1) - 1;
            $last = max($wl->dayNumber($dates['end'] ?? $start, $start), 1);
        } else {
            $first = -1;
            $last = 3;
        }
        // an out-of-range stored value stays selectable
        if ($cur !== '' && is_numeric($cur)) {
            $first = min($first, (int)$cur);
            $last = max($last, (int)$cur);
        }

        $lang = $wl->pageLang($ID);
        $html = '<select name="' . hsc($name) . '" id="' . $id . '">';
        if (!empty($this->opt['optional'])) {
            $html .= '<option value=""></option>';
        }
        for ($d = $first; $d <= $last; $d++) {
            $sel = ($cur !== '' && is_numeric($cur) && (int)$cur === $d) ? ' selected="selected"' : '';
            $html .= '<option value="' . $d . '"' . $sel . '>'
                . hsc($this->dayLabel($d, $start, $lang)) . '</option>';
        }
        $html .= '</select>';
        return $html;
    }

    protected function dayLabel(int $day, ?int $start, string $lang): string
    {
        if ($start === null) return (string)$day;
        $ts = strtotime(sprintf('%+d days', $day - 1), strtotime(date('Y-m-d', $start)));
        if (class_exists(\IntlDateFormatter::class)) {
            $fmt = new \IntlDateFormatter(
                $lang,
                \IntlDateFormatter::NONE,
                \IntlDateFormatter::NONE,
                date_default_timezone_get(),
                null,
                'EEEE d.M.'
            );
            $when = $fmt->format($ts);
        } else {
            $when = date('D d.m.', $ts);
        }
        return $day . ' · ' . $when;
    }
}
