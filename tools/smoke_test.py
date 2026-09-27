from pathlib import Path
import json,re,subprocess
root=Path(__file__).resolve().parents[1]
games=json.loads((root/'config/games.json').read_text())
assert len(games)==13 and len({g['code'] for g in games})==13
api=(root/'api/index.php').read_text()
for action in ['game_list','game_config','game_round','register','login','place_bet','settle_round','admin_login','admin_round_result','admin_game_update','admin_wallet_test','admin_players']:
    assert action in api
m=re.search(r"if\(\$action==='game_round'\).*?\n if\(\$action==='place_bet'",api,re.S)
assert m and 'player_id' not in m.group(0)
for f in ['bootstrap.php','index.php','setup_admin.php']:
    r=subprocess.run(['php','-l',str(root/'api'/f)],capture_output=True,text=True)
    assert r.returncode==0,r.stderr
for g in games:
    assert (root/'public/games'/f"{g['code']}.html").exists()
assert (root/'public/games/game-shell.css').exists()
print('SMOKE OK: 13 games, player auth, TEST wallet/bets/settlement, admin controls, no player-targeted round result control.')
