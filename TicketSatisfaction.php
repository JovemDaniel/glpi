<?php

/**
 * ---------------------------------------------------------------------
 *
 * GLPI - Gestionnaire Libre de Parc Informatique
 *
 * http://glpi-project.org
 *
 * @copyright 2015-2024 Teclib' and contributors.
 * @copyright 2003-2014 by the INDEPNET Development Team.
 * @licence   https://www.gnu.org/licenses/gpl-3.0.html
 *
 * ---------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of GLPI.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * ---------------------------------------------------------------------
 */

class TicketSatisfaction extends CommonDBTM
{
    public static $rightname = 'ticket';

    public $dohistory         = true;
    public $history_blacklist = ['date_answered'];


    public static function getTypeName($nb = 0)
    {
        return __('Satisfaction');
    }


    /**
     * for use showFormHeader
     **/
    public static function getIndexName()
    {
        return 'tickets_id';
    }


    public function getLogTypeID()
    {
        return ['Ticket', $this->fields['tickets_id']];
    }


    public static function canUpdate()
    {
        return (Session::haveRight('ticket', READ));
    }


    /**
     * Is the current user have right to update the current satisfaction
     *
     * @return boolean
     **/
    public function canUpdateItem()
    {

        $ticket = new Ticket();
        if (!$ticket->getFromDB($this->fields['tickets_id'])) {
            return false;
        }

       // you can't change if your answer > 12h
        if (
            !is_null($this->fields['date_answered'])
            && ((time() - strtotime($this->fields['date_answered'])) > (12 * HOUR_TIMESTAMP))
        ) {
            return false;
        }

        if (
            $ticket->isUser(CommonITILActor::REQUESTER, Session::getLoginUserID())
            || ($ticket->fields["users_id_recipient"] === Session::getLoginUserID() && Session::haveRight('ticket', Ticket::SURVEY))
            || (isset($_SESSION["glpigroups"])
              && $ticket->haveAGroup(CommonITILActor::REQUESTER, $_SESSION["glpigroups"]))
        ) {
            return true;
        }
        return false;
    }


    /**
     * form for satisfaction
     *
     * @param $ticket Object : the ticket
     **/
    // AJUSTE NA ABA DE SATISFAÇÃO
    public function showSatisactionForm($ticket)
    {
        $tid = $ticket->fields['id'];    
        $options = [];
        $options['colspan'] = 1;
    
        // Realiza a consulta no banco de dados para verificar se o chamado foi fechado como improcedente
        global $DB; // Usa a conexão global do GLPI
        $query = "SELECT * FROM `glpi_plugin_tag_tagitems` WHERE items_id = $tid";
        $result = $DB->query($query);
    
        if ($DB->numrows($result) > 0) {
            // Caso tenha resultados, o chamado foi fechado como improcedente
            echo "<div class='center spaced'>";
            echo "<p><strong>ESTE CHAMADO FOI FECHADO COMO IMPROCEDENTE</strong></p>";
            echo "</div>";
        } else {
            // Caso contrário, exibe o formulário de satisfação
            // for external inquest => link
            if ($this->fields["type"] == 2) {
                $url = Entity::generateLinkSatisfaction($ticket);
                echo "<div class='center spaced'>" .
                    "<a href='$url'>" . __('External survey') . "</a><br>($url)</div>";
            } else { // for internal inquest => form
                $this->showFormHeader($options);
    
                // Set default satisfaction to 3 if not set
                if (is_null($this->fields["satisfaction"])) {
                    $this->fields["satisfaction"] = 0;
                }
                echo "<tr class='tab_bg_2'>";
                echo "<td>" . __('Em uma escala de 0 a 10, o quanto você recomendaria nossos serviços de suporte técnico a um colega?') . "</td>";
                echo "<td>";
                echo "<input type='hidden' name='tickets_id' value='$tid'>";
    
                echo "<select id='satisfaction_data' name='satisfaction'>";
                for ($i = 0; $i <= 10; $i++) {  // De 0 a 10 estrelas
                    echo "<option value='$i' " . (($i == $this->fields["satisfaction"]) ? 'selected' : '') . ">$i</option>";
                }
                echo "</select>";
                echo "<div class='rateit' id='stars'></div>";
    
                // Campo de comentário, inicialmente não obrigatório
                echo "<div id='comment_section' style='display:none;'>";
                echo "</div>";
    
                echo "<script type='text/javascript'>";
                echo "$(function() {";
                echo "$('#stars').rateit({value: " . $this->fields["satisfaction"] . ",
                                           min : 0,
                                           max : 10,
                                           step: 1,
                                           backingfld: '#satisfaction_data',
                                           ispreset: true,
                                           resetable: false});";
    
                // Monitorar mudanças na seleção de estrelas
                echo "$('#satisfaction_data').change(function() {";
                echo "var rating = $(this).val();";
    
                // Se a avaliação for 8 ou menor, exibe o campo de comentário e torna-o obrigatório
                echo "if (rating <= 8) {";
                echo "$('#comment_section').show();";
                echo "$('textarea[name=\"comment\"]').prop('required', true);";
                echo "} else {";
                echo "$('#comment_section').hide();";
                echo "$('textarea[name=\"comment\"]').prop('required', false);";
                echo "}";
                echo "});";
    
                // Verificação ao enviar o formulário
                echo "$('form').submit(function(e) {";
                echo "var rating = $('#satisfaction_data').val();";
                echo "if (rating <= 8 && $('textarea[name=\"comment\"]').val() == '') {";
                echo "alert('Por favor, forneça um comentário para justificar a sua avaliação.');";
                echo "e.preventDefault();";  // Impede o envio do formulário
                echo "}";
                echo "});";
    
                echo "});</script>";
    
                echo "</td></tr>";
    
                // Campo de comentário já existente, agora com tamanho fixo
                echo "<tr class='tab_bg_2'>";
                echo "<td rowspan='1'>" . __('Quais foram os motivos que o levaram a atribuir essa nota?') . "</td>";
                echo "<td rowspan='1' class='middle'>";
                echo "<textarea class='form-control' rows='7' name='comment' style='resize: none;'>" . $this->fields["comment"] . "</textarea>";
                echo "</td></tr>";
    
                if ($this->fields["date_answered"] > 0) {
                    echo "<tr class='tab_bg_2'>";
                    echo "<td>" . __('Response date to the satisfaction survey') . "</td><td>";
                    echo Html::convDateTime($this->fields["date_answered"]) . "</td></tr>\n";
                }
    
                $options['candel'] = false;
                $this->showFormButtons($options);
            }
        }
    }
    


    public function prepareInputForUpdate($input)
    {
        if ($input['satisfaction'] >= 0) {
            $input["date_answered"] = $_SESSION["glpi_currenttime"];
        }

        return $input;
    }


    public function post_addItem()
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        if (!isset($this->input['_disablenotif']) && $CFG_GLPI["use_notifications"]) {
            $ticket = new Ticket();
            if ($ticket->getFromDB($this->fields['tickets_id'])) {
                NotificationEvent::raiseEvent("satisfaction", $ticket);
            }
        }
    }


    /**
     * @since 0.85
     **/
    public function post_UpdateItem($history = true)
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        if (!isset($this->input['_disablenotif']) && $CFG_GLPI["use_notifications"]) {
            // Send notification only if fields related to reply are updated.
            $answer_updates = array_filter(
                $this->updates,
                fn ($field) => in_array($field, ['satisfaction', 'comment'])
            );

            $ticket = new Ticket();
            if (count($answer_updates) > 1 && $ticket->getFromDB($this->fields['tickets_id'])) {
                NotificationEvent::raiseEvent("replysatisfaction", $ticket);
            }
        }
    }


    /**
     * display satisfaction value
     *
     * @param $value decimal between 0 and 5
     **/
    public static function displaySatisfaction($value)
    {
        if (is_null($value)) {
            return '';
        }

        if ($value < 0) {
            $value = 0;
        }
        if ($value > 5) {
            $value = 5;
        }

        $rand = mt_rand();
        $out = "<div id='rateit_$rand' class='rateit'></div>";
        $out .= Html::scriptBlock("
            $(function () {
                $('#rateit_$rand').rateit({
                    max: 5,
                    resetable: false,
                    value: $value,
                    readonly: true,
                });
            });
        ");
        return $out;
    }


    /**
     * Get name of inquest type
     *
     * @param $value status ID
     **/
    public static function getTypeInquestName($value)
    {

        switch ($value) {
            case 1:
                return __('Internal survey');

            case 2:
                return __('External survey');

            default:
               // Get value if not defined
                return $value;
        }
    }


    /**
     * @since 0.84
     *
     * @param $field
     * @param $values
     * @param $options   array
     **/
    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {

        if (!is_array($values)) {
            $values = [$field => $values];
        }
        switch ($field) {
            case 'type':
                return self::getTypeInquestName($values[$field]);
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }


    /**
     * @since 0.84
     *
     * @param $field
     * @param $name                  (default '')
     * @param $values                (default '')
     * @param $options   array
     **/
    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {

        if (!is_array($values)) {
            $values = [$field => $values];
        }
        $options['display'] = false;

        switch ($field) {
            case 'type':
                $options['value'] = $values[$field];
                $typeinquest = [1 => __('Internal survey'),
                    2 => __('External survey')
                ];
                return Dropdown::showFromArray($name, $typeinquest, $options);
        }
        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    public static function getFormURLWithID($id = 0, $full = true)
    {

        $satisfaction = new self();
        if (!$satisfaction->getFromDB($id)) {
            return '';
        }

        return Ticket::getFormURLWithID($satisfaction->fields['tickets_id']) . '&forcetab=Ticket$3';
    }
}
