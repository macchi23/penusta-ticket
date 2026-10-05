import type { ReactNode } from "react";

// 座席図の SVG 土台（viewBox・ピッチ）。ブロックは children で描く
export default function StadiumCanvas({
  viewbox,
  children,
}: {
  viewbox: { width: number; height: number };
  children: ReactNode;
}) {
  return (
    <svg
      viewBox={`0 0 ${viewbox.width} ${viewbox.height}`}
      className="w-full rounded-lg border border-gray-200 bg-emerald-50 dark:border-gray-700 dark:bg-gray-900"
    >
      {/* ピッチ */}
      <rect
        x={viewbox.width * 0.25}
        y={viewbox.height * 0.3}
        width={viewbox.width * 0.5}
        height={viewbox.height * 0.4}
        rx={8}
        fill="#16a34a"
      />
      <text
        x={viewbox.width / 2}
        y={viewbox.height / 2}
        textAnchor="middle"
        dominantBaseline="middle"
        fill="#ffffff"
        fontSize={20}
      >
        PITCH
      </text>
      {children}
    </svg>
  );
}
