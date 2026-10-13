/**
 * «Hoje» no fuso da aplicação (config `app.timezone`, Europe/Lisbon), e não no
 * dia UTC.
 *
 * `new Date().toISOString().slice(0, 10)` dá o dia em UTC: em Lisboa, no
 * horário de verão, entre as 00:00 e as 01:00 ainda devolve ONTEM — e um
 * registo feito a essa hora nascia no dia anterior, às vezes noutro período de
 * avaliação. O dia é lido com o fuso escrito por extenso, nunca com o do
 * dispositivo nem com um desvio fixo, para que as mudanças de hora se tratem
 * sozinhas.
 */
export function todayInTimeZone(timeZone: string, now: Date = new Date()): string {
    try {
        // en-CA escreve as datas como AAAA-MM-DD — o formato de um <input type="date">.
        return new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(now);
    } catch {
        // Um fuso desconhecido para este browser: o dia local do dispositivo é
        // melhor do que o dia UTC (em Portugal coincide com o da aplicação).
        const year = now.getFullYear();
        const month = String(now.getMonth() + 1).padStart(2, '0');
        const day = String(now.getDate()).padStart(2, '0');

        return `${year}-${month}-${day}`;
    }
}
