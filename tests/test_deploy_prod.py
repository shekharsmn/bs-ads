"""Run on Linux as root: python3 tests/test_deploy_prod.py.

All Docker/Git/database/network commands are fakes. Real file operations and
flock are confined to a temporary installation; no application is deployed.
"""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


FAKE = r'''#!/usr/bin/python3
import json, os, sys
from pathlib import Path
name = Path(sys.argv[0]).name
args = sys.argv[1:]
with open(os.environ['CALLS'], 'a') as log:
    log.write(json.dumps([name, *args]) + '\n')
if name == 'git':
    if 'rev-parse' in args:
        print('abcdef123456' if any(x.startswith('--short') for x in args) else 'a' * 40)
    elif 'status' in args:
        if os.environ.get('FAIL_STATUS'):
            sys.exit(2)
        if os.environ.get('DIRTY'):
            print(' M tracked-source.php')
        if os.environ.get('UNTRACKED'):
            print('?? unexpected-source.php')
    elif 'diff' in args:
        if 'services/meta-ad-library-scraper' in args:
            sys.exit(int(os.environ.get('SCRAPER_CHANGED', '0')))
        sys.exit(int(os.environ.get('DIRTY', '0')))
elif name == 'mysqldump':
    print('-- fixture database backup')
    sys.exit(int(os.environ.get('FAIL_BACKUP', '0')))
elif name == 'curl' or name == 'sleep':
    pass
elif name == 'docker':
    if args[0] == 'buildx':
        if args[1] == 'inspect': sys.exit(1)
        if args[1] == 'build': sys.exit(int(os.environ.get('FAIL_BUILD', '0')))
    elif args[0] == 'inspect':
        print('\n'.join(['true false healthy'] * 8))
    elif args[0] == 'compose':
        if 'config' in args and '--no-env-resolution' in args:
            print('services: {} # generated fixture')
        elif 'ps' in args and '-aq' in args:
            print('\n'.join('container' + str(i) for i in range(8)))
        elif 'run' in args:
            if 'migrate' in args: sys.exit(int(os.environ.get('FAIL_MIGRATION', '0')))
            if 'config:cache' in args: sys.exit(int(os.environ.get('FAIL_CACHE', '0')))
            if args[-2:] == ['php', 'php']:
                content = sys.stdin.read()
                if 'Refusing to deploy' in content:
                    if os.environ.get('FAIL_ENV'): sys.exit(1)
                    if not os.environ.get('NO_SCRAPER_TOKEN'):
                        print('SCRAPER_SERVICE_TOKEN=fixture-token')
    else:
        sys.exit('Unexpected Docker command')
else:
    sys.exit('Forbidden host service command: ' + name)
'''


@unittest.skipUnless(os.name == 'posix' and os.geteuid() == 0, 'Linux/root fixture required')
class DeploymentTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='bs-ads-script-test-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.state = self.root / '.deployment'
        self.state.mkdir()
        (self.root / 'backend').mkdir()
        script = Path(__file__).resolve().parents[1] / 'deploy-prod.sh'
        shutil.copy2(script, self.root / 'deploy-prod.sh')
        for file in ['compose.yaml', 'runtime.env', 'scraper.env', 'Dockerfile.php', 'Dockerfile.web']:
            (self.state / file).write_text('original-' + file + '\n')
        (self.state / 'deployed-revision').write_text('b' * 40)
        (self.root / 'backend/.env').write_text('APP_ENV=production\n')
        (self.root / '.dockerignore').write_text('**/.env\n')
        self.bin = self.root / 'bin'
        self.bin.mkdir()
        for name in ['docker', 'git', 'mysql', 'mysqldump', 'curl', 'sleep', 'systemctl', 'pm2', 'service', 'pkill', 'killall']:
            path = self.bin / name
            path.write_text(FAKE)
            path.chmod(0o755)
        self.log = self.root / 'calls.jsonl'

    def run_script(self, mode='deploy', **env):
        result = subprocess.run(
            ['bash', str(self.root / 'deploy-prod.sh'), mode],
            env={**os.environ, 'PATH': str(self.bin) + ':' + os.environ['PATH'],
                 'CALLS': str(self.log), **env},
            stdin=subprocess.DEVNULL, capture_output=True, text=True, timeout=20,
        )
        self.calls = [json.loads(line) for line in self.log.read_text().splitlines()] if self.log.exists() else []
        self.output = result.stdout + result.stderr
        return result.returncode

    def matching(self, *words):
        return [call for call in self.calls if all(word in call for word in words)]

    def assert_isolated(self):
        allowed = {'php', 'web', 'scheduler', 'worker-core', 'worker-audits', 'worker-library', 'worker-analysis', 'scraper'}
        for call in self.matching('docker', 'compose'):
            if call == ['docker', 'compose', 'version']:
                continue
            self.assertIn('-p', call)
            self.assertEqual(call[call.index('-p') + 1], 'bs-ads')
            if 'stop' in call:
                self.assertLessEqual(set(call[call.index('stop') + 1:]), allowed)
            if 'up' in call and 'artisan' not in call:
                self.assertIn('--no-deps', call)
                self.assertLessEqual({x for x in call[call.index('up') + 1:] if not x.startswith('-')}, allowed)
            self.assertNotIn('down', call[:call.index('artisan')] if 'artisan' in call else call)
        self.assertFalse(any(call[0] in {'systemctl', 'pm2', 'service', 'pkill', 'killall'} for call in self.calls))
        self.assertFalse(self.matching('docker', 'prune'))
        self.assertNotIn('fixture-token', self.output)

    def test_deploy_builds_before_maintenance_and_backs_up_before_migrating(self):
        self.assertEqual(self.run_script(), 0, self.output)
        build = self.matching('docker', 'buildx', 'build')
        self.assertEqual(len(build), 2)  # Unchanged scraper is not rebuilt.
        down = self.matching('artisan', 'down')[0]
        backup = self.matching('mysqldump')[0]
        migrate = self.matching('artisan', 'migrate')[0]
        self.assertLess(self.calls.index(build[-1]), self.calls.index(down))
        self.assertLess(self.calls.index(backup), self.calls.index(migrate))
        self.assertEqual(backup[-1], 'bs_ads')
        self.assertTrue(self.matching('artisan', 'config:cache'))
        self.assertTrue(self.matching('artisan', 'route:cache'))
        self.assertTrue(self.matching('artisan', 'view:cache'))
        self.assertTrue(self.matching('artisan', 'up'))
        self.assertFalse(self.matching('git', 'pull'))
        status = self.matching('git', 'status')[0]
        self.assertIn(':(exclude).deployment', status)
        self.assertEqual((self.state / 'scraper.env').read_text(), 'SCRAPER_SERVICE_TOKEN=fixture-token\n')
        releases = list((self.state / 'releases').iterdir())
        self.assertEqual(len(releases), 1)
        self.assertFalse((releases[0] / 'scraper.next.env').exists())
        self.assert_isolated()

    def test_restart_refreshes_existing_images_without_build_or_migration(self):
        self.assertEqual(self.run_script('restart'), 0, self.output)
        self.assertFalse(self.matching('docker', 'buildx'))
        self.assertFalse(self.matching('mysqldump'))
        self.assertFalse(self.matching('artisan', 'migrate'))
        self.assertTrue(self.matching('docker', 'up', '--force-recreate'))
        self.assertEqual((self.state / 'runtime.env').read_text(), 'APP_ENV=production\n')
        self.assert_isolated()

    def test_build_failure_does_not_pause_running_app(self):
        self.assertNotEqual(self.run_script(FAIL_BUILD='2'), 0)
        self.assertFalse(self.matching('artisan', 'down'))
        self.assertFalse(self.matching('docker', 'compose', 'stop'))
        self.assertEqual((self.state / 'compose.yaml').read_text(), 'original-compose.yaml\n')

    def test_migration_failure_restores_previous_config_without_reversing_database(self):
        self.assertNotEqual(self.run_script(FAIL_MIGRATION='42'), 0)
        self.assertTrue(self.matching('docker', 'up', '--force-recreate'))
        self.assertTrue(self.matching('artisan', 'up'))
        self.assertEqual((self.state / 'compose.yaml').read_text(), 'original-compose.yaml\n')
        self.assertEqual((self.state / 'runtime.env').read_text(), 'original-runtime.env\n')
        self.assertFalse(self.matching('rollback'))
        self.assert_isolated()

    def test_backup_failure_never_runs_migrations(self):
        self.assertNotEqual(self.run_script(FAIL_BACKUP='3'), 0)
        self.assertFalse(self.matching('artisan', 'migrate'))
        self.assertTrue(self.matching('artisan', 'up'))

    def test_cache_failure_after_migration_recovers_previous_services(self):
        self.assertNotEqual(self.run_script(FAIL_CACHE='4'), 0)
        self.assertTrue(self.matching('artisan', 'migrate'))
        self.assertTrue(self.matching('docker', 'up', '--force-recreate'))
        self.assertFalse(self.matching('artisan', 'rollback'))
        self.assertEqual((self.state / 'compose.yaml').read_text(), 'original-compose.yaml\n')

    def test_invalid_production_env_fails_before_maintenance_and_restores_runtime(self):
        self.assertNotEqual(self.run_script(FAIL_ENV='1'), 0)
        self.assertFalse(self.matching('artisan', 'down'))
        self.assertEqual((self.state / 'runtime.env').read_text(), 'original-runtime.env\n')

    def test_failed_health_check_recovers_previous_services(self):
        path = self.root / 'deploy-prod.sh'
        # Exercise deadline expiry immediately instead of waiting two minutes.
        path.write_text(path.read_text().replace('SECONDS + 120', 'SECONDS + 0'))
        self.assertNotEqual(self.run_script(), 0)
        self.assertTrue(self.matching('docker', 'up', '--force-recreate'))
        self.assertEqual((self.state / 'compose.yaml').read_text(), 'original-compose.yaml\n')

    def test_changed_scraper_gets_its_own_image(self):
        self.assertEqual(self.run_script(SCRAPER_CHANGED='1'), 0, self.output)
        self.assertEqual(len(self.matching('docker', 'buildx', 'build')), 3)
        self.assert_isolated()

    def test_dirty_tracked_code_is_rejected_before_building(self):
        self.assertNotEqual(self.run_script(DIRTY='1'), 0)
        self.assertFalse(self.matching('docker', 'buildx', 'build'))
        self.assertFalse(self.matching('artisan', 'down'))

    def test_untracked_source_is_rejected_before_building(self):
        self.assertNotEqual(self.run_script(UNTRACKED='1'), 0)
        self.assertFalse(self.matching('docker', 'buildx', 'build'))
        self.assertFalse(self.matching('artisan', 'down'))

    def test_source_status_failure_is_rejected_before_building(self):
        self.assertNotEqual(self.run_script(FAIL_STATUS='1'), 0)
        self.assertFalse(self.matching('docker', 'buildx', 'build'))
        self.assertFalse(self.matching('artisan', 'down'))

    def test_removing_scraper_token_clears_stale_runtime_secret(self):
        self.assertEqual(self.run_script('restart', NO_SCRAPER_TOKEN='1'), 0, self.output)
        self.assertEqual((self.state / 'scraper.env').read_text(), '')
        releases = list((self.state / 'releases').iterdir())
        self.assertEqual(len(releases), 1)
        self.assertFalse((releases[0] / 'scraper.next.env').exists())

    def test_concurrent_run_is_rejected(self):
        import fcntl
        with (self.state / 'deploy.lock').open('w') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            self.assertNotEqual(self.run_script(), 0)
        self.assertIn('Another BS Ads deployment', self.output)
        self.assertFalse(self.matching('artisan', 'down'))


if __name__ == '__main__':
    unittest.main()
