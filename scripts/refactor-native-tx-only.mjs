#!/usr/bin/env node
// ONE-TIME source tree rewrite. Never connects to a live database.
import { readFileSync as read, writeFileSync as write, readdirSync as ls, renameSync as mv, rmSync as rm } from 'node:fs';
import { join } from 'node:path';
const removePaths = [
'api/app/Console/Commands/DatabaseNativeCutover.php',
'api/app/Support/Database/AtomicNativeRename.php',
'api/app/Support/Database/NativeTableName.php',
'api/app/Support/Database/ResolvesNativeEloquentTable.php',
'api/config/database_native.php',
'api/tests/Unit/Support/Database/AtomicNativeRenameTest.php',
'api/tests/Unit/Support/Database/NativeRuntimeQueriesTest.php',
'api/tests/Unit/Support/Database/NativeTableNameTest.php',
'api/tests/Unit/Support/Database/ResolvesNativeEloquentTableTest.php',
'api/scripts/database-atomic-rename-roundtrip.php',
'api/scripts/database-native-full-roundtrip.php',
'scripts/database-native-cutover-plan.mjs',
'scripts/tests/database-native-cutover-plan.test.mjs',
'scripts/database-mysql-parity.mjs',
'scripts/tests/database-mysql-parity.test.mjs',
'scripts/database-schema-inventory.mjs',
'scripts/tests/database-schema-inventory.test.mjs',
'scripts/database-runtime-reference-gate.mjs',
'scripts/tests/database-runtime-reference-gate.test.mjs',
'scripts/database-native-migration-gate.mjs',
'scripts/tests/database-native-migration-gate.test.mjs',
'docs/operations/native-mysql-table-cutover.md',
'api/database/migrations/2026_09_21_000001_migrate_legacy_branding_to_txboard.php',
'api/database/migrations/2026_10_08_000002_purge_legacy_frontend_appearance.php',
'api/database/migrations/2026_10_10_000001_purge_retired_node_and_captcha_settings.php',
'scripts/refactor-native-tx-only.mjs',
'.github/workflows/native-only-refactor.yml'
];
const removed = new Set(removePaths);
const ignores = new Set(['.git','node_modules','vendor','dist','build','coverage','artifacts']);
let changed=0;
function walk(dir){
 for(const d of ls(dir,{withFileTypes:true})){
  if(ignores.has(d.name))continue;
  const p=join(dir,d.name),relative=p.replace(/^\.\//,'').replaceAll('\\','/');
  if(d.isDirectory()){walk(p);continue;}
  if(!d.isFile() || removed.has(relative))continue;
  if(!(/\.(php|mjs|js|ts|tsx|vue|md|json|yml|yaml|sh|sql|xml|txt|html|env|example)$/.test(relative) || relative.endsWith('.env.example')))continue;
  let content=read(p,'utf8'),next=content;
  next=next.replace(/(?:\\?App\\Support\\Database\\)?NativeTableName::runtime\(\s*(['"])v2_([a-z][a-z0-9_]*)\1\s*\)/g,"'tx_$2'");
  next=next.replace(/^\s*use (?:\\?App\\Support\\Database\\)?NativeTableName;\s*\n/gm,'\n');
  next=next.replace(/^\s*use (?:\\?App\\Support\\Database\\)?ResolvesNativeEloquentTable;\s*\n/gm,'\n');
  next=next.replaceAll('v2_','tx_');
  next=next.split('\n').filter(line=>!/^\s*TX_NATIVE_TABLES\s*=/.test(line)).join('\n');
  if(next!==content){write(p,next);changed++;}
 }
}
walk('.');
const mig='api/database/migrations';let renamed=0;
for(const file of ls(mig).filter(x=>x.endsWith('.php')&&x.includes('v2_'))){
  mv(join(mig,file),join(mig,file.replaceAll('v2_','tx_')));renamed++;
}
for(const f of removePaths)if(f!=='scripts/refactor-native-tx-only.mjs'&&f!=='.github/workflows/native-only-refactor.yml')rm(f,{force:true});
// Make MySQL uniqueness metadata unconditional in native tx_* mode.
const file='api/app/Support/Audit/MySqlSchemaInventory.php';
let src=read(file,'utf8');
src=src.replace(/\s*\/\/ The same uniqueness invariants apply after an approved [\s\S]*?\$prefix = \$hasNative \? 'tx_' : 'tx_';/,'');
src=src.replace(/\$prefix \. 'user'/g,"'tx_user'").replace(/\$prefix \. 'order'/g,"'tx_order'").replace(/\$prefix \. 'traffic_batch'/g,"'tx_traffic_batch'");
write(file,src);
rm('scripts/refactor-native-tx-only.mjs',{force:true});
rm('.github/workflows/native-only-refactor.yml',{force:true});
console.log(JSON.stringify({changed,renamed,removed:removePaths.length}));
