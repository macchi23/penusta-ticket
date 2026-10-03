import { pickOnSaleMatch, pickReservedBlock } from './lib/api';

const FRONT = 'http://localhost:3100';

export default async function globalSetup(): Promise<void> {
  try {
    const front = await fetch(`${FRONT}/`);
    const api = await fetch('http://localhost:8080/api/health');
    if (!front.ok || !api.ok) throw new Error(`front ${front.status} / api ${api.status}`);
  } catch (e) {
    throw new Error(`dev 環境に接続できない。docker compose up -d を実行してください (${String(e)})`);
  }

  // dev の初回コンパイルを済ませておく
  const matchId = await pickOnSaleMatch();
  const blockId = await pickReservedBlock(matchId);
  for (const path of [`/matches/${matchId}?block=${blockId}`, `/matches/${matchId}/checkout`, '/orders/1']) {
    await fetch(`${FRONT}${path}`);
  }
}
