import { pickAdmin, pickEditableUnsoldMatch, pickOnSaleMatch, pickReservedBlock, pickUsers } from './lib/api';

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

  await fetch(`${FRONT}/__e2e-warmup-not-found`);

  // 管理画面（一覧・エディタ・403・401）。対象試合が無くても他の spec は動かすので握りつぶす
  try {
    const adminId = await pickAdmin();
    const [customerId] = await pickUsers(1);
    const as = (id?: number) => (id === undefined ? {} : { headers: { Cookie: `penusta_demo_user=${id}` } });
    const adminMatch = await pickEditableUnsoldMatch(adminId);
    await fetch(`${FRONT}/admin`, as(adminId));
    await fetch(`${FRONT}/admin/matches/${adminMatch}/allocations`, as(adminId));
    await fetch(`${FRONT}/admin`, as(customerId));
    await fetch(`${FRONT}/admin`);
  } catch {
    // 対象無しは spec 側の beforeAll が分かるメッセージで落とす
  }
}
