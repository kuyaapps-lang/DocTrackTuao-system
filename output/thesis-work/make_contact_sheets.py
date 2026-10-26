from pathlib import Path
from PIL import Image

directory = Path('output/thesis-qa/final-render')
pages = sorted(directory.glob('page-*.png'), key=lambda path: int(path.stem.split('-')[1]))

for offset in range(0, len(pages), 9):
    sheet = Image.new('RGB', (1260, 1782), 'white')
    for index, page in enumerate(pages[offset:offset + 9]):
        image = Image.open(page).convert('RGB').resize((420, 594))
        sheet.paste(image, ((index % 3) * 420, (index // 3) * 594))
    sheet.save(directory / f'contact-{offset // 9 + 1}.png')
