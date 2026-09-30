#!/usr/bin/env python3
"""Build static Linux agents, checksummed assets and the <=10MiB XBoard upload ZIP."""
import gzip,hashlib,json,os,pathlib,shutil,subprocess,sys,zipfile
root=pathlib.Path(__file__).resolve().parents[1]
out=pathlib.Path(sys.argv[1]).resolve() if len(sys.argv)>1 else root/'dist'
out.mkdir(parents=True,exist_ok=True)
from runtime import generate
shutil.rmtree(root/'plugin/NodeQuality/Runtime',ignore_errors=True)
generate(root)
assets=root/'plugin/NodeQuality/resources/assets'
go=os.environ.get('GO','go')
version=json.loads((root/'plugin/NodeQuality/config.json').read_text())['version']
for arch in ['amd64','arm64']:
    binary=out/f'node-quality-agent-linux-{arch}'
    subprocess.run([go,'build','-buildvcs=false','-trimpath','-ldflags=-s -w','-o',str(binary),'.'],cwd=root/'agent',env={**os.environ,'GOOS':'linux','GOARCH':arch,'CGO_ENABLED':'0'},check=True)
    (assets/(binary.name+'.gz')).write_bytes(gzip.compress(binary.read_bytes(),compresslevel=9,mtime=0))
    binary.unlink()
shutil.copy2(root/'docs/NEXTTRACE_LICENSE',assets/'NextTrace.LICENSE')
shutil.copy2(root/'docs/THIRD_PARTY.md',root/'plugin/NodeQuality/THIRD_PARTY.md')
manifest={'version':version,'files':{p.name:hashlib.sha256(p.read_bytes()).hexdigest() for p in assets.iterdir() if p.is_file() and p.name not in {'manifest.json','source.zip'}}}
(assets/'manifest.json').write_text(json.dumps(manifest,indent=2)+'\n')
shutil.copy2(root/'LICENSE',root/'plugin/NodeQuality/LICENSE')
shutil.copy2(root/'README.md',root/'plugin/NodeQuality/README.md')
# Source packages contain only maintained project inputs, never deployment state.
source_dirs={'agent','plugin','scripts','tests','docs','.github'}
source_files={'README.md','LICENSE','CONTRIBUTING.md','SECURITY.md','.gitignore','package.json','package-lock.json'}
generated={'plugin/NodeQuality/README.md','plugin/NodeQuality/LICENSE','plugin/NodeQuality/THIRD_PARTY.md','plugin/NodeQuality/resources/assets/manifest.json','plugin/NodeQuality/resources/assets/NextTrace.LICENSE'}
def source_paths(directory):
    for p in sorted(directory.iterdir()):
        rel=p.relative_to(root)
        if p.is_symlink():
            raise SystemExit(f'Symlinks are not allowed in source packages: {rel}')
        if p == out or p.name in {'node_modules','dist','.git','.work','__pycache__','Runtime'}:
            continue
        if len(rel.parts)==1 and p.name not in source_dirs | source_files:
            continue
        if p.is_dir():
            yield from source_paths(p)
        elif (str(rel) not in generated and not p.name.startswith('.env')
              and not p.name.endswith(('.gz','.zip','.pyc','.sqlite','.db','.log'))
              and '.sqlite-' not in p.name):
            yield p
with zipfile.ZipFile(out/f'node-quality-source-{version}.zip','w',zipfile.ZIP_DEFLATED,compresslevel=9) as z:
    for p in source_paths(root):
        z.write(p,pathlib.Path('node-quality')/p.relative_to(root))
shutil.copy2(out/f'node-quality-source-{version}.zip',assets/'source.zip')
manifest['files']['source.zip']=hashlib.sha256((assets/'source.zip').read_bytes()).hexdigest()
(assets/'manifest.json').write_text(json.dumps(manifest,indent=2)+'\n')
with zipfile.ZipFile(out/f'NodeQuality-{version}.zip','w',zipfile.ZIP_DEFLATED,compresslevel=9) as z:
    for p in sorted((root/'plugin').rglob('*')):
        if p.is_symlink():raise SystemExit(f'Symlinks are not allowed in plugin packages: {p}')
        if p.is_file():z.write(p,p.relative_to(root/'plugin'))
size=(out/f'NodeQuality-{version}.zip').stat().st_size
if size>10*1024*1024:raise SystemExit('Plugin ZIP exceeds XBoard 10MiB upload limit')
shutil.copy2(root/'README.md',out/'README.md')
shutil.copy2(root/'docs/VALIDATION.md',out/'VALIDATION.md')
(out/'SHA256SUMS').write_text(''.join(f'{hashlib.sha256(p.read_bytes()).hexdigest()}  {p.name}\n' for p in sorted(out.iterdir()) if p.is_file() and p.name!='SHA256SUMS'))
print(json.dumps({'plugin_zip_bytes':size,'outputs':str(out)},indent=2))
