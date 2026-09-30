"""Version PHP implementation classes so Octane cannot reuse older loaded plugin classes."""
from pathlib import Path

def generate(root):
    plugin=Path(root)/'plugin/NodeQuality'
    target=plugin/'Runtime/V040'
    for part in ['Services','Controllers']:
        (target/part).mkdir(parents=True,exist_ok=True)
        for source in (plugin/part).glob('*.php'):
            (target/part/source.name).write_text(source.read_text().replace('Plugin\\NodeQuality\\','Plugin\\NodeQuality\\Runtime\\V040\\'))

if __name__=='__main__':generate(Path(__file__).resolve().parents[1])
