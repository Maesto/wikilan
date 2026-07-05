<?php

use dokuwiki\plugin\struct\meta\Value;

/**
 * Bureaucracy field type `wikilan_host`: the event.host struct field
 * rendered as a dropdown of the active edition's attendees instead of a
 * free-text user input. Extending struct's bureaucracy field means struct's
 * TEMPLATE_SAVE hook stores the value exactly like a struct_field.
 *
 * Form syntax:  wikilan_host "=@USER@" !
 */
class helper_plugin_wikilan_host extends helper_plugin_struct_field
{
    public function initialize($args)
    {
        // the column is fixed — usage omits the schema.field argument
        array_splice($args, 1, 0, ['event.host']);
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
        $input = $this->userSelect($field, $name, $id);

        $html = '<div class="field">';
        $html .= "<label class=\"$lclass\" data-column=\"$colname\" for=\"$id\">";
        $html .= "<span class=\"label $class\" title=\"$hint\">$trans$required</span>";
        $html .= '</label>';
        $html .= "<span class=\"input\">$input</span>";
        $html .= '</div>';

        return $html;
    }

    protected function userSelect(Value $field, string $name, string $id): string
    {
        /** @var helper_plugin_wikilan $wl */
        $wl = plugin_load('helper', 'wikilan');

        $cur = $field->getRawValue();
        if (is_array($cur)) $cur = (string)reset($cur);
        $cur = (string)$cur;

        $users = [];
        $lan = $wl->activeLan();
        if ($lan) {
            foreach ($wl->attendees((int)$lan['id']) as $a) {
                $users[] = $a['user'];
            }
        }
        // current value (creator prefill, or a host who unattended) stays selectable
        if ($cur !== '' && !in_array($cur, $users, true)) $users[] = $cur;
        usort($users, static fn($a, $b) => strcasecmp($wl->userName($a), $wl->userName($b)));

        $html = '<select name="' . hsc($name) . '" id="' . $id . '">';
        if (!empty($this->opt['optional'])) {
            $html .= '<option value=""></option>';
        }
        foreach ($users as $u) {
            $label = $wl->userName($u);
            if ($label !== $u) $label .= " ($u)";
            $html .= '<option value="' . hsc($u) . '"'
                . ($u === $cur ? ' selected="selected"' : '')
                . '>' . hsc($label) . '</option>';
        }
        $html .= '</select>';
        return $html;
    }
}
