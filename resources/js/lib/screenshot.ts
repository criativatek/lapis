import { detectPersonalData } from '@/lib/personalData';

/**
 * A captura do ecrã de quem está a reportar.
 *
 * DUAS VIAS, E A ORDEM IMPORTA. `getDisplayMedia` com `preferCurrentTab` dá uma
 * imagem verdadeira do que a pessoa vê — incluindo o que o browser desenha por
 * cima — ao custo de uma autorização, e só existe em contexto seguro (em
 * `http://` local, `navigator.mediaDevices` nem sequer está definido). Quando
 * falta ou é recusada, redesenha-se o DOM.
 *
 * `modern-screenshot`, E NÃO `html2canvas`. O segundo é o óbvio e não serve
 * aqui: traz um analisador de CSS próprio que rebenta com
 * `Attempting to parse an unsupported color function "oklch"` — e `oklch()` é o
 * formato em que o Tailwind 4 escreve **todas** as cores deste projecto. Não é
 * configurável. O `modern-screenshot` clona o DOM para um `foreignObject` de
 * SVG, o que faz o próprio browser interpretar o CSS: o que ele desenha é o que
 * ele desenhava de qualquer maneira.
 *
 * JPEG A 0.85, E NUNCA PNG. `toDataURL('image/png', 0.8)` ignora o argumento de
 * qualidade em silêncio — o PNG não tem compressão com perda —, e o resultado
 * são ficheiros grandes de mais que o servidor recusa com um 422 que chega ao
 * utilizador como «o botão de reportar dá erro». É o defeito que o sistema
 * equivalente do Plaanly documenta em comentários, e não vale a pena repeti-lo.
 *
 * O WIDGET ESCONDE-SE ANTES. Uma captura com o próprio diálogo de reporte lá
 * dentro é inútil, e a espera de 300 ms é o que dá ao browser tempo de repintar.
 */

/** O que o ecrã mostra, para a certificação não ser às cegas. */
export type ScreenshotWarning = string | null;

export type Screenshot = {
    file: File;
    /** Para a pré-visualização. Libertado por quem a deixa de mostrar. */
    url: string;
    warning: ScreenshotWarning;
};

const QUALITY = 0.85;

/**
 * O aviso base: nunca ausente.
 *
 * A AUSÊNCIA DE AVISO NÃO PODE LER-SE COMO «ESTA IMAGEM É SEGURA». O detector
 * reconhece FORMATOS — emails, telefones, identificadores — e um nome próprio
 * não tem formato nenhum que o distinga de outra palavra qualquer. A página de
 * resultados de uma turma, que é uma tabela com seis nomes de crianças contra as
 * suas classificações, não dispara detector nenhum. Se o aviso só aparecesse
 * quando algo é reconhecido, o ecrã mais perigoso do produto seria justamente o
 * único a não avisar de nada.
 */
const ALWAYS = 'A aplicação não consegue reconhecer nomes de alunos numa imagem.';

/**
 * O que a aplicação sabe que está no ecrã, dito em português.
 *
 * Quando reconhece alguma coisa, di-lo em concreto — e aí o aviso vale mais,
 * porque a aplicação desenhou aquela página e sabe o que lá pôs. Quando não
 * reconhece nada, continua a avisar: ver o «ALWAYS» acima.
 */
export function warnAbout(text: string): ScreenshotWarning {
    const findings = detectPersonalData(text);

    if (findings.length === 0) {
        return ALWAYS;
    }

    const kinds = [...new Set(findings.map((finding) => finding.label))];

    return `Esta página mostra ${kinds.slice(0, 3).join(', ')}. ${ALWAYS}`;
}

async function viaDisplayMedia(): Promise<Blob | null> {
    if (!navigator.mediaDevices?.getDisplayMedia) {
        return null;
    }

    let stream: MediaStream | null = null;

    try {
        stream = await navigator.mediaDevices.getDisplayMedia({
            // Pede o separador actual. O browser pode na mesma oferecer outra
            // coisa — e é por isso que a pré-visualização existe: a pessoa vê o
            // que vai enviar antes de o enviar.
            video: { displaySurface: 'browser' } as MediaTrackConstraints,
            audio: false,
            preferCurrentTab: true,
            selfBrowserSurface: 'include',
        } as DisplayMediaStreamOptions);

        // Pelo `<video>`, e não pelo `ImageCapture`: este último não existe no
        // Safari nem no Firefox, e onde existe nem sempre traz tipos. Um
        // elemento de vídeo lê qualquer `MediaStream` em qualquer browser que
        // tenha chegado até aqui.
        const video = document.createElement('video');
        video.srcObject = stream;
        video.muted = true;

        await video.play();
        // Um fotograma de espera: sem isto, o primeiro desenho sai preto.
        await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));

        const canvas = document.createElement('canvas');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        canvas.getContext('2d')?.drawImage(video, 0, 0);

        video.pause();
        video.srcObject = null;

        return await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', QUALITY));
    } catch {
        return null;
    } finally {
        stream?.getTracks().forEach((track) => track.stop());
    }
}

async function viaDomClone(): Promise<Blob | null> {
    try {
        const { domToBlob } = await import('modern-screenshot');

        return await domToBlob(document.body, {
            type: 'image/jpeg',
            quality: QUALITY,
            // Em ecrãs de alta densidade a escala por omissão multiplica a área
            // por quatro, e um telemóvel fica sem memória a meio.
            scale: window.innerWidth < 768 ? 1 : Math.min(window.devicePixelRatio, 2),
        });
    } catch {
        return null;
    }
}

/**
 * Tira a captura, escondendo primeiro o que for pedido.
 *
 * @param hide elementos a esconder durante a captura — o widget, o diálogo.
 */
export async function capture(hide: HTMLElement[] = []): Promise<Screenshot | null> {
    const previous = hide.map((element) => element.style.visibility);
    hide.forEach((element) => (element.style.visibility = 'hidden'));

    await new Promise((resolve) => setTimeout(resolve, 300));

    let blob: Blob | null = null;

    try {
        blob = (await viaDisplayMedia()) ?? (await viaDomClone());
    } finally {
        hide.forEach((element, index) => (element.style.visibility = previous[index] ?? ''));
    }

    if (blob === null) {
        return null;
    }

    return {
        file: new File([blob], 'ecra.jpg', { type: 'image/jpeg' }),
        url: URL.createObjectURL(blob),
        // Corre sobre o TEXTO da página, não sobre a imagem: a aplicação não lê
        // imagens, mas sabe o que desenhou.
        warning: warnAbout(document.body.innerText ?? ''),
    };
}
