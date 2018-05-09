<?php
/*
 * Rack Plugin: display a rack elevation from a plaintext source
 * 
 * Each rack is enclosed in <rack>...</rack> tags. The rack tag can have the
 * following parameters (all optional):
 *   name=<name>     The name of the rack (default: 'Rack')
 *   height=<U>      The height of the rack, in U (default: 42)
 *   descending      Indicates that the rack is numbered top-to-bottom
 * Between these tags is a series of lines, each describing a piece of equipment:
 * 
 *   <u_bottom> <u_size> <model> [name] [#color] [link:<URL>] [comment]
 * 
 * The fields:
 *  - <u_bottom>: The starting (bottom-most) U of the equipment.
 *  - <u_size>: The height of the equipment in U.
 *  - <model>: The model name or other description of the item (e.g. 
 *    "Cisco 4948" or "Patch panel"). If it has spaces, enclose it in quotes.
 *  - [name]: Optional. The hostname or other designator of this specific item 
 *    (e.g. “rtpserver1”). If it has spaces, enclose it in quotes. If you want 
 *    to specify a comment but no name, use ”” as the name.
 *  - [#color]: Optional. The color of the item is normally automatically 
 *    picked based on the model, but you can override it by specifying a 
 *    #RRGGBB HTML color after the model/name.
 *  - [link:http://url]: Optional. The model name will now link to the url given.
 *  - [comment]: Optional. After the name (and possibly color), and remaining 
 *    text on the line is treated as free-form comment. Comments are visible 
 *    by hovering the mouse over the equipment in the rack. Items with comments
 *    are designated with an asterisk * after the model name.
 *
 * You can also include comment lines starting with a pound sign #. 
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Tyler Bletsch <Tyler.Bletsch@netapp.com>
 * @version    20120816.1
 * 
 * Modded for links usage by Sylvain Bigonneau <s.bigonneau@moka-works.com>
 * Improved link syntax contributed by Dokuwiki user "schplurtz".
 */

if(!defined('DOKU_INC')) define('DOKU_INC',realpath(dirname(__FILE__).'/../../').'/');
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'syntax.php');

/*
 * All DokuWiki plugins to extend the parser/rendering mechanism
 * need to inherit from this class
 */
class syntax_plugin_rack extends DokuWiki_Syntax_Plugin {

	/*
	 * return some info
	 */
	function getInfo(){
		return array(
			'author' => 'Tyler Bletsch',
			'email'  => 'Tyler.Bletsch@netapp.com',
			'date'   => '2010-01-18',
			'name'   => 'Rack Elevation Plugin',
			'desc'   => 'Displays an elevation of a datacenter rack.',
			'url'    => 'http://www.dokuwiki.org/plugin:rack',
		);
	}

	/*
	 * What kind of syntax are we?
	 */
	function getType(){
		return 'substition';
	}

	/*
	 * Where to sort in?
	 */
	function getSort(){
		return 155;
	}

	/*
	 * Paragraph Type
	 */
	function getPType(){
		return 'block';
	}

	/*
	 * Connect pattern to lexer
	 */
	function connectTo($mode) {
		$this->Lexer->addSpecialPattern("<rack[^>]*>.*?(?:<\/rack>)",$mode,'plugin_rack');
	}


	/*
	 * Handle the matches
	 */
	function handle($match, $state, $pos, &$handler){
		$match = substr($match,5,-7);

		//default options
		$opt = array(
			'name' => 'Rack',
			'height' => 42,
			'content' => ''
		);

		list($optstr,$opt['content']) = explode('>',$match,2);
		unset($match);

		// parse options
		$optsin = explode(' ',$optstr);
		foreach($optsin as $o){
			$o = trim($o);
			if (preg_match("/^name=(.+)/",$o,$matches)) {
				$opt['name'] = $matches[1];
			} elseif (preg_match("/^height=(\d+)/",$o,$matches)) {
				$opt['height'] = $matches[1];
			} elseif (preg_match("/^descending/",$o,$matches)) {
				$opt['descending'] = 1;
			}
		}

		return $opt;
	}

	function autoselect_color($item) {
		$color = '#888';
		if (preg_match('/(wire|cable)\s*guide|pdu|patch|term server|lcd/i',$item['model'])) { $color = '#bba'; }
		if (preg_match('/blank/i',                                         $item['model'])) { $color = '#fff'; }
		if (preg_match('/netapp|fas\d/i',                                  $item['model'])) { $color = '#07c'; }
		if (preg_match('/^Sh(elf)?\s/i',                                   $item['model'])) { $color = '#0AE'; }
		if (preg_match('/cisco|catalyst|nexus/i',                          $item['model'])) { $color = '#F80'; }
		if (preg_match('/brocade|mds/i',                                   $item['model'])) { $color = '#8F0'; }
		if (preg_match('/ucs/i',                                           $item['model'])) { $color = '#c00'; }
		if (preg_match('/ibm/i',                                           $item['model'])) { $color = '#67A'; }
		if (preg_match('/hp/i',                                            $item['model'])) { $color = '#A67'; }
		if (!$item['model']) { $color = '#FFF'; }
		return $color;
	}

	/*
	 * Create output
	 */
	function render($mode, &$renderer, $opt) {
		if($mode == 'metadata') return false;

		$content = $opt['content'];

		// clear any trailing or leading empty lines from the data set
		$content = preg_replace("/[\r\n]*$/","",$content);
		$content = preg_replace("/^\s*[\r\n]*/","",$content);

		if(!trim($content)){
			$renderer->cdata('No data found');
		}
		
		$items = array();
		
		$csv_id = uniqid("csv_");
		$csv = "Model,Name,Rack,U,Height,Comment\n";
		
		foreach (explode("\n",$content) as $line) {
			$item = array();
			if (preg_match("/^\s*#/",$line) || !trim($line)) { continue; } # skip comments & blanks
			#                     Ustart    Usize     Model                    Name?                                             Color?       Link?               Comment
			if (preg_match('/^\s* (\d+) \s+ (\d+) \s+ ((?:"[^"]*")|\S+) \s* ((?:"[^"]*")|(?!(?:(?:\#)|(?:link:)))\S*)? \s* (\#\w+)? \s* ( link: (?: (?:\[\[[^]|]+(?:\|[^]]*)?]]) | \S* ) )? \s* (.*?)? \s* $/x',$line,$matches)) {
				$item['u_bottom'] = $matches[1];
				$item['u_size'] = $matches[2];
				$item['model'] = preg_replace('/^"/','',preg_replace('/"$/','',$matches[3]));
				$item['name'] = preg_replace('/^"/','',preg_replace('/"$/','',$matches[4]));
				$item['color'] = $matches[5] ? $matches[5] : $this->autoselect_color($item);
				$item['linktitle'] = '';
				$item['link'] = substr($matches[6], 5);
				if( '[' == substr($item['link'], 0, 1)) {
					if(preg_match( '/^\[\[[^|]+\|([^]]+)]]$/', $item['link'], $titlematch )) {
						$item['linktitle'] = ' title="'.hsc($titlematch[1]). '"';
					}
					$item['link']=wl(cleanID(preg_replace( '/^\[\[([^]|]+).*/', '$1', $item['link'] )));
				}
				$item['comment'] = $matches[7];
				$item['u_top'] = $item['u_bottom'] + ($opt['descending']?-1:1)*($item['u_size'] - 1);
				$items[$item['u_top']] = $item;
				$csv .= "\"$item[model]\",\"$item[name]\",$opt[name],$item[u_bottom],$item[u_size],\"$item[comment]\"\n";
			} else {
				#$renderer->doc .= "Syntax error on the following line: <pre style='color:red'>$line</pre>\n";
				$renderer->doc .= 'Syntax error on the following line: <pre style="color:red">'.hsc($line)."</pre>\n";
			}
		}
		
		#$renderer->doc .= "<pre>ALL\n".print_r($items,true)."</pre>";
		
		$u_first = $opt['descending'] ? 1 : $opt['height'];
		$u_last  = $opt['descending'] ? $opt['height'] : 1; 
		$u_delta = $opt['descending'] ? +1 : -1;
		$renderer->doc .= "<table class='rack'><tr><th colspan='2' class='title'>$opt[name]</th></tr>\n";
		#for ($u=$opt['height']; $u>=1; $u--) {
		#foreach (range($u_first,$u_last,$u_delta) as $u) {
		for ($u=$u_first;  ($opt['descending'] ? $u<=$u_last : $u>=$u_last);  $u += $u_delta) {
			if ($items[$u] && $items[$u]['model']) {	
				$item = $items[$u];
				$renderer->doc .= 
					"<tr><th>$u</th>".
					"<td class='item' rowspan='$item[u_size]' style='background-color: $item[color];' title=\"".htmlspecialchars($item['comment'])."\">".
					($item['link'] ? '<a href="'.$item['link'].'"'.$item['linktitle'].'>' : '').
					"<div style='float: left; font-weight:bold;'>".
						"$item[model]" .
						($item['comment'] ? ' *' : '').
					"</div>".
					"<div style='float: right; margin-left: 3em; '>$item[name]".
					"</div>".
					($item['link'] ? '</a>' : '').
					"</td></tr>\n";
				for ($d = 1; $d < $item['u_size']; $d++) {
					$u += $u_delta;
					$renderer->doc .= "<tr><th>$u</th></tr>\n";
				}
			} else {
				$renderer->doc .= "<Tr><Th>$u</th><td class='empty'></td></tr>\n";
			}
		}
		# we use a whole row as a bottom border to sidestep an asinine rendering bug in firefox 3.0.10
		$renderer->doc .= "<tr><th colspan='2' class='bottom'><span style='cursor: pointer;' onclick=\"this.innerHTML = rack_toggle_vis(document.getElementById('$csv_id'),'block')?'Hide CSV &uarr;':'Show CSV &darr;';\">Show CSV &darr;</span></th></tr>\n";
		$renderer->doc .= "</table>&nbsp;";
		
		# this javascript hack sets the CSS "display" property of the tables to "inline", 
		# since IE is too dumb to have heard of the "inline-table" mode.
		$renderer->doc .= "<script type='text/javascript'>rack_ie6fix();</script>\n";

		$renderer->doc .= "<pre style='display:none;' id='$csv_id'>$csv</pre>\n";
		
		return true;
	}
	
}
