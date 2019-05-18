<?php
namespace Civi\Cv\Command;

use Civi\Cv\Encoder;
use Civi\Cv\Util\Api4ArgParser;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Api4Command extends BaseCommand {

  use \Civi\Cv\Util\BootTrait;

  /**
   * @var array
   */
  public $defaults;

  /**
   * @param string|null $name
   */
  public function __construct($name = NULL) {
    $this->defaults = array('version' => 4, 'checkPermissions' => FALSE);
    parent::__construct($name);
  }

  protected function configure() {
    $C = '<comment>';
    $_C = '</comment>';
    $I = '<info>';
    $_I = '</info>';

    $this
      ->setName('api4')
      ->setDescription('Call APIv4')
      ->addOption('in', NULL, InputOption::VALUE_REQUIRED, 'Input format (args,json)', 'args')
      ->addOption('out', NULL, InputOption::VALUE_REQUIRED, 'Output format (' . implode(',', Encoder::getTabularFormats()) . ')', Encoder::getDefaultFormat())
      ->addOption('dry-run', 'N', InputOption::VALUE_NONE, 'Preview the API call. Do not execute.')
      ->addArgument('Entity.action', InputArgument::REQUIRED)
      ->addArgument('key=value', InputArgument::IS_ARRAY)
      ->setHelp("
When passing arguments to APIv4, this command supports a few input formats.
For scripting with untrusted data, the most precise way to input information
is to pipe JSON. For quick manual usage, one can pass parameters inline
(as part of the main command).

{$C}Pipe Data (JSON):${_C}

    {$C}echo{$_C} {$I}JSON{$_I} | {$C}cv api4${_C} {$I}ENTITY{$_I}.{$I}ACTION{$_I} {$C}--in=json${_C}

{$C}Inline Data (JSON):${_C}

    {$C}cv api4${_C} {$I}ENTITY{$_I}.{$I}ACTION{$_I} {$I}KEY{$_I}={$I}VALUE{$_I}... {$I}JSON-OBJECT...{$_I}

    Use ${I}KEY{$_I}={$I}VALUE{$_I} to set an input to a specific value. The value may be a bare string
    or it may be JSON (beginning with '[' or '{' or '\"').

    Similarly, a parameter which begins with '{' will be interpreted as a JSON expression.

{$C}Inline Data (+Options):${_C}

    {$C}cv api4${_C} {$I}ENTITY{$_I}.{$I}ACTION{$_I} +{$I}OP{$_I}={$I}EXPR{$_I}...

    Some inputs are common and cumbersome to type in JSON. To allow quicker data entry,
    they support special syntaxes in the form +{$I}OP{$_I}={$I}EXPR{$_I} or +{$I}OP{$_I} {$I}EXPR{$_I}. For example:

    Option         Examples
    {$C}+s{$_C}|{$C}+select{$_C}     +select=id,display_name
                   +select id,display_name
                   +s id,display_name
    {$C}+w{$_C}|{$C}+where{$_C}      +where 'first_name like \"Adams%\"'
                   +w 'first_name like \"Adams%\"'
    {$C}+o{$_C}|{$C}+orderBy{$_C}    +orderBy last_name,first_name
                   +o last_name,first_name
                   +o 'last_name DESC,first_name ASC'
    {$C}+l{$_C}|{$C}+limit{$_C}      +limit 15@60
                   +l 15
    {$C}+v{$_C}|{$C}+value{$_C}      +v name=Alice
                   +v name=Alice


    For + options, the \"=\" may be replaced by a single space or \":\".

{$C}Example: Get all contacts{$_C}
    cv api4 Contact.get

{$C}Example: Get ten contacts (KEY=VALUE){$_C}
    cv api4 Contact.get select='[\"display_name\"]' limit=10

{$C}Example: Find ten contacts named \"Adam\" (+Options){$_C}
    cv api4 Contact.get +select=display_name +where='display_name LIKE \"Adam%\" limit=10'

{$C}Example: Find ten contacts named \"Adam\"  (JSON){$_C}
    echo '{\"select\":[\"display_name\"],\"where\":[[\"display_name\",\"LIKE\",\"Adam%\"]],\"limit\":10}' | cv api4 contact.get --in=json

{$C}Example: Find contact names for IDs between 100 and 200, ordered by last name{$_C}
    cv api4 Contact.get +s display_name +o last_name +w 'id >= 100' +w 'id <= 200'

{$C}Example: Change do_not_phone for everyone named Adam{$_C}
    cv api4 Contact.update +w 'display_name like %Adam%' +v do_not_phone=1

NOTE: To change the default output format, set CV_OUTPUT.
");
    $this->configureBootOptions();
  }

  protected function execute(InputInterface $input, OutputInterface $output) {
    $C = '<comment>';
    $_C = '</comment>';
    $I = '<info>';
    $_I = '</info>';

    $this->boot($input, $output);

    if (!function_exists('civicrm_api4')) {
      throw new \RuntimeException("Please enable APIv4 before running APIv4 commands.");
    }

    list($entity, $action) = explode('.', $input->getArgument('Entity.action'));
    $params = $this->parseParams($input);
    if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE || $input->getOption('dry-run')) {
      $output->writeln("{$I}Entity{$_I}: {$C}$entity{$_C}");
      $output->writeln("{$I}Action{$_I}: {$C}$action{$_C}");
      $output->writeln("{$I}Params{$_I}: " . json_encode($params, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    if ($input->getOption('dry-run')) {
      return 0;
    }
    $result = \civicrm_api4($entity, $action, $params);

    $out = $input->getOption('out');
    if (!in_array($out, Encoder::getFormats()) && in_array($out, Encoder::getTabularFormats())) {
      // For tabular output, we have to be picky about what data to display.
      if ($action !== 'get' || !$result) {
        $output->getErrorOutput()
          ->writeln("<error>The output format \"$out\" only works with tabular data. Try using a \"get\" API. Forcing format to \"json-pretty\".</error>");
        $input->setOption('out', 'json-pretty');
        $this->sendResult($input, $output, $result);
      }
      else {
        $columns = empty($params['select']) ? array_keys($result->first()) : explode(',', $params['select']);
        $this->sendTable($input, $output, (array) $result, $columns);
      }
    }
    else {
      $this->sendResult($input, $output, $result);
    }

    return empty($result['is_error']) ? 0 : 1;
  }

  /**
   * @param \Symfony\Component\Console\Input\InputInterface $input
   * @param $matches
   * @return array
   */
  protected function parseParams(InputInterface $input) {
    $args = $input->getArgument('key=value');
    switch ($input->getOption('in')) {
      case 'args':
        $p = new Api4ArgParser();
        $params = $p->parse($args, $this->defaults);
        break;

      case 'json':
        $json = stream_get_contents(STDIN);
        if (empty($json)) {
          $params = $this->defaults;
        }
        else {
          $params = array_merge($this->defaults, json_decode($json, TRUE));
        }
        break;

      default:
        throw new \RuntimeException('Unknown input format');
    }

    return $params;
  }

}