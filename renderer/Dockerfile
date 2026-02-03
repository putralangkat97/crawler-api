FROM oven/bun:1

WORKDIR /app

COPY package.json bun.lockb* ./
RUN bun install --frozen-lockfile

COPY tsconfig.json ./
COPY src ./src

RUN bun run build

ENV NODE_ENV=production
EXPOSE 3001

CMD ["bun", "dist/server.js"]