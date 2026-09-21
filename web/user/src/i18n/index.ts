import { computed, ref } from 'vue'

type Catalog = Record<string, string>

const zhCN:Catalog={
  'common.language':'语言','common.logout':'退出登录','common.loading':'加载中…','common.actions':'操作',
  'common.detail':'详情','common.cancel':'取消','common.back':'返回','common.previous':'上一页','common.next':'下一页',
  'common.page':'第 {page} 页','common.total':'共 {total} 条','common.none':'暂无记录','common.refresh':'刷新',
  'common.time':'时间','common.status':'状态','common.type':'类型','common.amount':'金额','common.create':'创建',
  'common.close':'关闭','common.submit':'提交','common.confirm':'确认','common.search':'搜索','common.all':'全部',

  'nav.dashboard':'仪表盘','nav.groupBilling':'财务','nav.groupSubscription':'订阅','nav.groupAccount':'账户','nav.plan':'套餐','nav.order':'订单','nav.node':'节点','nav.knowledge':'知识库',
  'nav.invite':'邀请','nav.giftCard':'礼品卡','nav.traffic':'流量','nav.ticket':'工单','nav.profile':'个人设置',

  'auth.login':'登录','auth.register':'注册','auth.forget':'找回密码','auth.email':'邮箱','auth.emailUsername':'邮箱用户名','auth.password':'密码',
  'auth.newPassword':'新密码','auth.confirmPassword':'确认密码','auth.emailCode':'邮箱验证码','auth.sendCode':'发送验证码',
  'auth.inviteCode':'邀请码','auth.mailLink':'使用邮箱链接登录','auth.passwordLogin':'返回密码登录',
  'auth.mailLinkTitle':'邮箱链接登录','auth.mailLinkDesc':'我们会向邮箱发送一次性登录链接。','auth.welcome':'欢迎回来',
  'auth.loginDesc':'登录后管理你的订阅与订单。','auth.createAccount':'创建账号','auth.registerDesc':'填写账号信息开始使用。',
  'auth.resetPassword':'重置密码','auth.resetDesc':'通过邮箱验证码设置新密码。','auth.processing':'处理中…',
  'auth.sendLoginLink':'发送登录链接','auth.registerAndLogin':'注册并登录','auth.termsPrefix':'我已阅读并同意',
  'auth.terms':'服务条款','auth.orTelegram':'或使用 Telegram','auth.emailRequired':'请输入邮箱','auth.mailLinkSent':'登录链接已发送，请检查邮箱','auth.passwordRequired':'请输入密码','auth.passwordMismatch':'两次输入的密码不一致','auth.passwordTooShort':'密码至少 8 位','auth.registrationClosed':'当前已停止注册','auth.inviteRequired':'请输入邀请码','auth.emailCodeRequired':'请输入邮箱验证码','auth.termsRequired':'请先同意服务条款','auth.passwordResetSuccess':'密码已重置，请使用新密码登录','auth.emailFirst':'请先输入邮箱','auth.codeSent':'验证码已发送，请检查邮箱','auth.defaultDescription':'简洁、可靠的订阅与服务管理中心。','auth.codePlaceholder':'验证码','auth.optional':'可选','auth.tosHint':'登录即表示你同意服务条款',

  'dashboard.overview':'概览','dashboard.hello':'你好，{name}','dashboard.desc':'快速查看订阅、流量、订单和服务状态。',
  'dashboard.buyRenew':'购买 / 续费','dashboard.subscription':'我的订阅','dashboard.active':'有效',
  'dashboard.importClient':'一键导入客户端','dashboard.copyLink':'复制订阅链接','dashboard.copied':'已复制',
  'dashboard.nodes':'查看节点','dashboard.noSubscription':'暂时没有有效订阅',
  'dashboard.noSubscriptionDesc':'选择一个套餐后即可获取订阅链接和节点。','dashboard.selectPlan':'选择套餐',
  'dashboard.pendingOrders':'待付款订单','dashboard.pendingTickets':'处理中工单','dashboard.invitedUsers':'邀请用户',
  'dashboard.balance':'账户余额','dashboard.news':'公告','dashboard.shortcut':'快捷入口','dashboard.quickSub':'快捷订阅','dashboard.quickSubDesc':'将订阅一键导入客户端','dashboard.purchase':'购买订阅','dashboard.purchaseDesc':'选择套餐并创建订单','dashboard.support':'提交工单','dashboard.supportDesc':'遇到问题时联系管理员','dashboard.unpaidAlert':'你有 {count} 个待支付订单','dashboard.ticketAlert':'你有 {count} 个处理中工单','dashboard.inviteAlert':'你已邀请 {count} 位用户','dashboard.goView':'去查看','dashboard.announcement':'公告','dashboard.copyFailed':'复制失败，请手动复制订阅链接','dashboard.longTerm':'长期有效','dashboard.expires':'到期：{date}','dashboard.device':'设备 {count}','dashboard.speed':'限速 {speed} Mbps','dashboard.resetDay':'每月 {day} 日重置','dashboard.trafficUsage':'流量使用','dashboard.used':'已使用 {percent}%','dashboard.ordersHint':'点击查看订单','dashboard.ticketsHint':'查看服务支持','dashboard.inviteHint':'查看邀请收益','dashboard.balanceHint':'可用于支付订单',

  'plan.title':'选择套餐','plan.desc':'根据流量、周期和价格选择适合你的订阅。','plan.empty':'暂无可售套餐',
  'plan.defaultDesc':'稳定可靠的订阅套餐。','plan.billing':'计费周期','plan.detailsCoupon':'详情 / 优惠券',
  'plan.quickBuy':'快速购买','plan.creating':'创建订单中…','plan.detailTitle':'套餐详情',
  'plan.detailDesc':'选择周期、验证优惠券并创建订单。','plan.back':'返回套餐','plan.choosePeriod':'选择计费周期',
  'plan.noPeriod':'当前套餐没有可购买周期','plan.coupon':'优惠券','plan.couponPlaceholder':'输入优惠码',
  'plan.validate':'验证','plan.couponValid':'优惠券有效：{discount}','plan.currentSelection':'当前选择',
  'plan.price':'价格','plan.discount':'优惠','plan.createOrder':'创建订单','plan.noDescription':'暂无套餐说明','period.month':'月付','period.quarter':'季付','period.halfYear':'半年付','period.year':'年付','period.twoYear':'两年付','period.threeYear':'三年付','period.onetime':'一次性','period.reset':'重置流量',

  'order.title':'我的订单','order.desc':'查看订阅购买、续费与支付状态。',
  'order.allStatus':'全部状态','order.pending':'待支付','order.processing':'开通中','order.cancelled':'已取消',
  'order.completed':'已完成','order.discounted':'已折抵','order.tradeNo':'订单号','order.plan':'套餐','order.period':'周期',
  'order.createdAt':'创建时间','order.payDetail':'支付 / 详情','order.empty':'暂无订单','order.confirmCancel':'确认取消该订单？',
  'order.new':'新购','order.renewal':'续费','order.upgrade':'升级','order.resetFlow':'重置流量','order.generic':'订单',
  'order.detailTitle':'订单详情','order.product':'商品','order.payment':'支付方式','order.noPayment':'没有可用支付方式；余额足够时仍可尝试直接结算。',
  'order.orderType':'订单类型','order.planTraffic':'套餐流量','order.paidAt':'支付时间','order.amountDetail':'金额明细',
  'order.orderAmount':'订单金额','order.balancePaid':'余额支付','order.handlingFee':'手续费','order.total':'应付金额',
  'order.payNow':'立即支付','order.paying':'支付处理中…','order.cancelOrder':'取消订单','order.qrPay':'扫码支付',
  'order.qrHint':'支付完成后页面会每 3 秒自动检查订单状态。','order.stripeLoading':'Stripe 卡表单尚未准备完成','order.stripeToken':'无法创建 Stripe 支付令牌','order.redirectOpened':'已打开支付页面，请完成支付后返回本页。','order.popupBlocked':'浏览器阻止了支付弹窗，请允许弹窗后重试。','order.back':'返回订单',

  'node.title':'节点','node.desc':'查看当前套餐可以使用的服务节点。','node.online':'在线','node.offline':'离线',
  'node.rate':'倍率','node.tags':'标签','node.name':'节点名称','node.empty':'暂无节点','node.subscribeHint':'当前暂无可用节点，可先购买或续费套餐。',

  'traffic.title':'流量明细','traffic.desc':'查看历史流量记录与节点倍率。','traffic.records':'记录数',
  'traffic.points':'流量统计点','traffic.upload':'上传','traffic.download':'下载','traffic.total':'总流量',
  'traffic.history':'历史累计','traffic.sum':'上传 + 下载','traffic.rate':'节点倍率','traffic.empty':'暂无流量记录','traffic.hint':'流量统计已按节点倍率折算为实际使用量。',

  'knowledge.title':'知识库','knowledge.desc':'查看使用教程、常见问题与公告文档。','knowledge.search':'搜索文章…',
  'knowledge.noMatch':'没有匹配的文章','knowledge.select':'请选择文章','knowledge.allCategory':'全部分类',

  'ticket.title':'工单','ticket.desc':'提交问题并与管理员持续沟通。','ticket.new':'新建工单','ticket.subject':'主题',
  'ticket.priority':'优先级','ticket.replyStatus':'回复状态','ticket.updatedAt':'更新时间','ticket.view':'查看',
  'ticket.empty':'暂无工单','ticket.type':'工单类型','ticket.uncategorized':'未分类','ticket.low':'低','ticket.medium':'中',
  'ticket.high':'高','ticket.description':'问题描述','ticket.submit':'提交工单','ticket.submitting':'提交中…',
  'ticket.processing':'处理中','ticket.closed':'已关闭','ticket.waitReply':'待回复','ticket.replied':'已回复',
  'ticket.me':'我','ticket.admin':'管理员','ticket.noMessages':'暂无消息','ticket.reply':'回复',
  'ticket.close':'关闭工单','ticket.send':'发送回复','ticket.confirmClose':'确认关闭该工单？','ticket.processingAction':'处理中…',

  'invite.title':'邀请与佣金','invite.desc':'分享邀请链接，查看佣金并转入余额或申请提现。',
  'invite.people':'邀请人数','invite.orders':'佣金订单','invite.totalCommission':'累计佣金','invite.validCommission':'有效佣金','invite.pendingCommission':'确认中佣金','invite.validCommissionHint':'已确认佣金累计','invite.pendingCommissionHint':'等待佣金确认','invite.available':'可用佣金',
  'invite.accumulated':'累计邀请','invite.accumulatedOrders':'累计订单','invite.historyIncome':'历史收益',
  'invite.availableHint':'可转余额 / 可提现','invite.codes':'邀请码','invite.generate':'生成邀请码',
  'invite.visits':'访问 {count} 次','invite.copyLink':'复制邀请链接','invite.noCodes':'暂无邀请码',
  'invite.transfer':'佣金转余额','invite.transferAmount':'转入金额（元）','invite.max':'最多 {amount}',
  'invite.minimum':'最低操作金额：{amount}','invite.feePreview':'手续费 {rate}%：{fee}；预计到账 {net}',
  'invite.transferBalance':'转入账户余额','invite.withdraw':'佣金提现',
  'invite.withdrawDesc':'提现请求会创建工单，由管理员审核和打款。','invite.withdrawMethod':'提现方式',
  'invite.withdrawAccount':'提现账户','invite.withdrawPlaceholder':'银行卡 / 支付宝 / USDT 地址等',
  'invite.withdrawable':'当前可提现：{amount}','invite.withdrawMinimum':'最低提现：{amount}',
  'invite.feeRate':'手续费率：{rate}%','invite.submitWithdraw':'提交提现申请','invite.history':'佣金明细',
  'invite.commission':'佣金','invite.distribution':'多级分佣','invite.baseRate':'基础佣金率','invite.level1':'一级分佣',
  'invite.level2':'二级分佣','invite.level3':'三级分佣','invite.generated':'邀请码已生成',
  'invite.transferred':'佣金已转入余额','invite.withdrawSubmitted':'提现申请已创建为工单，请在工单页面查看处理进度',
  'invite.linkCopied':'邀请链接已复制','invite.invalidAmount':'请输入正确的金额','invite.insufficient':'可用佣金不足','invite.withdrawClosed':'当前未开放佣金提现','invite.selectMethod':'请选择提现方式','invite.enterAccount':'请输入提现账户','invite.copyFailed':'复制失败',

  'gift.title':'礼品卡','gift.desc':'检查礼品卡奖励并兑换到当前账号。','gift.enter':'输入兑换码',
  'gift.enterDesc':'先检查奖励内容，确认后再兑换。','gift.checking':'检查中…','gift.check':'检查兑换码',
  'gift.canRedeem':'可兑换','gift.cannotRedeem':'不可兑换','gift.confirmRedeem':'确认兑换','gift.history':'兑换记录',
  'gift.code':'兑换码','gift.template':'模板','gift.reward':'奖励','gift.detail':'礼品卡使用详情','gift.loading':'加载中…',
  'gift.redeemTime':'兑换时间','gift.planAtUse':'使用时套餐','gift.levelAtUse':'使用时等级','gift.multiplier':'倍率',
  'gift.rewards':'本次奖励','gift.inviteReward':'邀请奖励','gift.inviter':'邀请人：{email}','gift.noHistory':'暂无兑换记录',
  'gift.balanceReward':'余额 ¥ {amount}','gift.trafficReward':'流量 {amount} GB','gift.expireReward':'有效期 +{days} 天',
  'gift.planReward':'套餐 #{id}','gift.deviceReward':'设备限制 {count}','gift.rewardFallback':'奖励详情以兑换结果为准','gift.redeemed':'已兑换：{name}','gift.notes':'备注',

  'profile.title':'个人设置','profile.desc':'管理账户密码、活动会话和安全凭据。','profile.account':'账户信息',
  'profile.email':'邮箱','profile.planId':'套餐 ID','profile.balance':'余额','profile.commissionBalance':'佣金余额',
  'profile.changePassword':'修改密码','profile.oldPassword':'旧密码','profile.newPassword':'新密码',
  'profile.confirmNewPassword':'确认新密码','profile.savePassword':'保存新密码','profile.sessions':'活动会话',
  'profile.created':'创建 {time}','profile.lastUsed':'最近使用 {time}','profile.remove':'移除',
  'profile.noSessions':'暂无活动会话','profile.security':'安全操作','profile.securityDesc':'重置安全凭据会使旧凭据失效。',
  'profile.quickLogin':'生成快速登录链接','profile.resetSecurity':'重置安全凭据','profile.passwordUpdated':'密码已更新',
  'profile.quickCopied':'快速登录链接已复制','profile.securityReset':'安全凭据已重置',
  'profile.passwordMismatch':'两次输入的新密码不一致','profile.confirmRemove':'确认移除该登录会话？','profile.confirmReset':'重置安全凭据会使旧的订阅凭据失效，确认继续？',

  'client.auto':'自动','client.copySubscription':'复制订阅链接','client.title':'导入订阅到客户端','client.desc':'根据设备筛选可用客户端，也可以指定协议类型。','client.copy':'复制链接','client.open':'一键打开','client.empty':'当前设备没有匹配的一键导入客户端，请直接复制订阅链接。','notFound.title':'页面不存在','notFound.desc':'你访问的地址不存在或已被移动。','notFound.back':'返回仪表盘',
}

const enUS:Catalog={
  'common.language':'Language','common.logout':'Log out','common.loading':'Loading…','common.actions':'Actions',
  'common.detail':'Details','common.cancel':'Cancel','common.back':'Back','common.previous':'Previous','common.next':'Next',
  'common.page':'Page {page}','common.total':'{total} total','common.none':'No records','common.refresh':'Refresh',
  'common.time':'Time','common.status':'Status','common.type':'Type','common.amount':'Amount','common.create':'Create',
  'common.close':'Close','common.submit':'Submit','common.confirm':'Confirm','common.search':'Search','common.all':'All',

  'nav.dashboard':'Dashboard','nav.groupBilling':'Billing','nav.groupSubscription':'Subscription','nav.groupAccount':'Account','nav.plan':'Plans','nav.order':'Orders','nav.node':'Nodes','nav.knowledge':'Knowledge',
  'nav.invite':'Invite','nav.giftCard':'Gift Card','nav.traffic':'Traffic','nav.ticket':'Tickets','nav.profile':'Profile',

  'auth.login':'Log in','auth.register':'Register','auth.forget':'Forgot password','auth.email':'Email','auth.emailUsername':'Email username','auth.password':'Password',
  'auth.newPassword':'New password','auth.confirmPassword':'Confirm password','auth.emailCode':'Email code','auth.sendCode':'Send code',
  'auth.inviteCode':'Invite code','auth.mailLink':'Use email sign-in link','auth.passwordLogin':'Back to password login',
  'auth.mailLinkTitle':'Email sign-in link','auth.mailLinkDesc':'We will send a one-time sign-in link to your email.',
  'auth.welcome':'Welcome back','auth.loginDesc':'Manage your subscription and orders after signing in.',
  'auth.createAccount':'Create account','auth.registerDesc':'Enter your account details to get started.',
  'auth.resetPassword':'Reset password','auth.resetDesc':'Set a new password using the email verification code.',
  'auth.processing':'Processing…','auth.sendLoginLink':'Send sign-in link','auth.registerAndLogin':'Register and log in',
  'auth.termsPrefix':'I have read and agree to the','auth.terms':'Terms of Service','auth.orTelegram':'or use Telegram','auth.emailRequired':'Enter your email','auth.mailLinkSent':'Sign-in link sent. Check your email.','auth.passwordRequired':'Enter your password','auth.passwordMismatch':'The passwords do not match','auth.passwordTooShort':'Password must be at least 8 characters','auth.registrationClosed':'Registration is currently closed','auth.inviteRequired':'Enter an invite code','auth.emailCodeRequired':'Enter the email verification code','auth.termsRequired':'Please accept the Terms of Service','auth.passwordResetSuccess':'Password reset. Sign in with your new password.','auth.emailFirst':'Enter your email first','auth.codeSent':'Verification code sent. Check your email.','auth.defaultDescription':'A simple and reliable subscription and service center.','auth.codePlaceholder':'Verification code','auth.optional':'Optional','auth.tosHint':'By signing in, you agree to the Terms of Service',

  'dashboard.overview':'Overview','dashboard.hello':'Hello, {name}',
  'dashboard.desc':'Quickly review your subscription, traffic, orders, and service status.','dashboard.buyRenew':'Buy / Renew',
  'dashboard.subscription':'My subscription','dashboard.active':'Active','dashboard.importClient':'Import to client',
  'dashboard.copyLink':'Copy subscription','dashboard.copied':'Copied','dashboard.nodes':'View nodes',
  'dashboard.noSubscription':'No active subscription','dashboard.noSubscriptionDesc':'Choose a plan to get your subscription URL and nodes.',
  'dashboard.selectPlan':'Choose plan','dashboard.pendingOrders':'Pending orders','dashboard.pendingTickets':'Open tickets',
  'dashboard.invitedUsers':'Invited users','dashboard.balance':'Balance','dashboard.news':'News','dashboard.shortcut':'Shortcuts','dashboard.quickSub':'Quick subscription','dashboard.quickSubDesc':'Import your subscription into a client','dashboard.purchase':'Purchase subscription','dashboard.purchaseDesc':'Choose a plan and create an order','dashboard.support':'Support ticket','dashboard.supportDesc':'Contact support when you need help','dashboard.unpaidAlert':'You have {count} unpaid orders','dashboard.ticketAlert':'You have {count} open tickets','dashboard.inviteAlert':'You have invited {count} users','dashboard.goView':'View','dashboard.announcement':'Announcement','dashboard.copyFailed':'Copy failed. Copy the subscription URL manually.','dashboard.longTerm':'No expiry','dashboard.expires':'Expires: {date}','dashboard.device':'Devices {count}','dashboard.speed':'Speed limit {speed} Mbps','dashboard.resetDay':'Resets on day {day} each month','dashboard.trafficUsage':'Traffic usage','dashboard.used':'{percent}% used','dashboard.ordersHint':'View orders','dashboard.ticketsHint':'View support','dashboard.inviteHint':'View invite earnings','dashboard.balanceHint':'Available for order payments',

  'plan.title':'Choose a plan','plan.desc':'Choose a subscription by traffic, billing period, and price.','plan.empty':'No plans available',
  'plan.defaultDesc':'A stable and reliable subscription plan.','plan.billing':'Billing period','plan.detailsCoupon':'Details / Coupon',
  'plan.quickBuy':'Quick buy','plan.creating':'Creating order…','plan.detailTitle':'Plan details',
  'plan.detailDesc':'Choose a billing period, validate a coupon, and create an order.','plan.back':'Back to plans',
  'plan.choosePeriod':'Choose billing period','plan.noPeriod':'This plan has no purchasable period','plan.coupon':'Coupon',
  'plan.couponPlaceholder':'Enter coupon code','plan.validate':'Validate','plan.couponValid':'Coupon valid: {discount}',
  'plan.currentSelection':'Selected','plan.price':'Price','plan.discount':'Discount','plan.createOrder':'Create order',
  'plan.noDescription':'No plan description','period.month':'Monthly','period.quarter':'Quarterly','period.halfYear':'Half-year','period.year':'Yearly','period.twoYear':'Two-year','period.threeYear':'Three-year','period.onetime':'One-time','period.reset':'Reset traffic',

  'order.title':'My orders','order.desc':'Review purchases, renewals, and payment status.',
  'order.allStatus':'All statuses','order.pending':'Pending','order.processing':'Processing','order.cancelled':'Cancelled',
  'order.completed':'Completed','order.discounted':'Discounted','order.tradeNo':'Order No.','order.plan':'Plan','order.period':'Period',
  'order.createdAt':'Created','order.payDetail':'Pay / Details','order.empty':'No orders','order.confirmCancel':'Cancel this order?',
  'order.new':'New','order.renewal':'Renewal','order.upgrade':'Upgrade','order.resetFlow':'Reset traffic','order.generic':'Order',
  'order.detailTitle':'Order details','order.product':'Product','order.payment':'Payment method',
  'order.noPayment':'No payment method is available; direct settlement can still work if balance covers the order.',
  'order.orderType':'Order type','order.planTraffic':'Plan traffic','order.paidAt':'Paid at','order.amountDetail':'Amount details',
  'order.orderAmount':'Order amount','order.balancePaid':'Balance payment','order.handlingFee':'Handling fee','order.total':'Total due',
  'order.payNow':'Pay now','order.paying':'Processing payment…','order.cancelOrder':'Cancel order','order.qrPay':'Scan to pay',
  'order.qrHint':'The page checks the order status every 3 seconds after payment.','order.stripeLoading':'Stripe card form is not ready yet','order.stripeToken':'Unable to create Stripe payment token','order.redirectOpened':'Payment page opened. Complete payment and return here.','order.popupBlocked':'The browser blocked the payment popup. Allow popups and retry.','order.back':'Back to orders',

  'node.title':'Nodes','node.desc':'View service nodes available to your current plan.','node.online':'Online','node.offline':'Offline',
  'node.rate':'Rate','node.tags':'Tags','node.name':'Node name','node.empty':'No nodes','node.subscribeHint':'No node is currently available. Purchase or renew a plan first.',

  'traffic.title':'Traffic details','traffic.desc':'Review historical traffic records and node multipliers.',
  'traffic.records':'Records','traffic.points':'Traffic samples','traffic.upload':'Upload','traffic.download':'Download',
  'traffic.total':'Total traffic','traffic.history':'Historical total','traffic.sum':'Upload + Download',
  'traffic.rate':'Node rate','traffic.empty':'No traffic records','traffic.hint':'Traffic values are normalized by the node rate to show actual usage.',

  'knowledge.title':'Knowledge base','knowledge.desc':'Browse tutorials, FAQs, and documentation.','knowledge.search':'Search articles…',
  'knowledge.noMatch':'No matching articles','knowledge.select':'Select an article','knowledge.allCategory':'All categories',

  'ticket.title':'Tickets','ticket.desc':'Submit an issue and continue the conversation with support.','ticket.new':'New ticket',
  'ticket.subject':'Subject','ticket.priority':'Priority','ticket.replyStatus':'Reply status','ticket.updatedAt':'Updated',
  'ticket.view':'View','ticket.empty':'No tickets','ticket.type':'Ticket type','ticket.uncategorized':'Uncategorized',
  'ticket.low':'Low','ticket.medium':'Medium','ticket.high':'High','ticket.description':'Description','ticket.submit':'Submit ticket',
  'ticket.submitting':'Submitting…','ticket.processing':'Open','ticket.closed':'Closed','ticket.waitReply':'Waiting reply',
  'ticket.replied':'Replied','ticket.me':'Me','ticket.admin':'Admin','ticket.noMessages':'No messages','ticket.reply':'Reply',
  'ticket.close':'Close ticket','ticket.send':'Send reply','ticket.confirmClose':'Close this ticket?','ticket.processingAction':'Processing…',

  'invite.title':'Invites & commission','invite.desc':'Share invite links, review commission, transfer it to balance, or request withdrawal.',
  'invite.people':'Invited users','invite.orders':'Commission orders','invite.totalCommission':'Total commission','invite.validCommission':'Valid commission','invite.pendingCommission':'Pending commission','invite.validCommissionHint':'Confirmed commission total','invite.pendingCommissionHint':'Awaiting commission confirmation',
  'invite.available':'Available commission','invite.accumulated':'Total invited','invite.accumulatedOrders':'Total orders',
  'invite.historyIncome':'Historical income','invite.availableHint':'Transferable / withdrawable','invite.codes':'Invite codes',
  'invite.generate':'Generate code','invite.visits':'{count} visits','invite.copyLink':'Copy invite link','invite.noCodes':'No invite codes',
  'invite.transfer':'Transfer commission','invite.transferAmount':'Transfer amount','invite.max':'Max {amount}',
  'invite.minimum':'Minimum: {amount}','invite.feePreview':'Fee {rate}%: {fee}; estimated net {net}',
  'invite.transferBalance':'Transfer to balance','invite.withdraw':'Withdraw commission',
  'invite.withdrawDesc':'Withdrawal creates a ticket for administrator review and payout.','invite.withdrawMethod':'Withdrawal method',
  'invite.withdrawAccount':'Withdrawal account','invite.withdrawPlaceholder':'Bank / Alipay / USDT address, etc.',
  'invite.withdrawable':'Withdrawable: {amount}','invite.withdrawMinimum':'Minimum withdrawal: {amount}',
  'invite.feeRate':'Fee rate: {rate}%','invite.submitWithdraw':'Submit withdrawal','invite.history':'Commission history',
  'invite.commission':'Commission','invite.distribution':'Multi-level commission','invite.baseRate':'Base commission rate',
  'invite.level1':'Level 1','invite.level2':'Level 2','invite.level3':'Level 3','invite.generated':'Invite code generated',
  'invite.transferred':'Commission transferred to balance',
  'invite.withdrawSubmitted':'Withdrawal ticket created. Track its progress on the Tickets page.','invite.linkCopied':'Invite link copied','invite.invalidAmount':'Enter a valid amount','invite.insufficient':'Insufficient commission balance','invite.withdrawClosed':'Commission withdrawal is currently disabled','invite.selectMethod':'Choose a withdrawal method','invite.enterAccount':'Enter a withdrawal account','invite.copyFailed':'Copy failed',

  'gift.title':'Gift Card','gift.desc':'Check gift card rewards and redeem them to this account.','gift.enter':'Enter gift code',
  'gift.enterDesc':'Preview the reward before confirming redemption.','gift.checking':'Checking…','gift.check':'Check code',
  'gift.canRedeem':'Redeemable','gift.cannotRedeem':'Unavailable','gift.confirmRedeem':'Redeem','gift.history':'Redemption history',
  'gift.code':'Code','gift.template':'Template','gift.reward':'Reward','gift.detail':'Gift card usage details','gift.loading':'Loading…',
  'gift.redeemTime':'Redeemed at','gift.planAtUse':'Plan at use','gift.levelAtUse':'Level at use','gift.multiplier':'Multiplier',
  'gift.rewards':'Reward','gift.inviteReward':'Invite reward','gift.inviter':'Inviter: {email}','gift.noHistory':'No redemption history',
  'gift.balanceReward':'Balance ¥ {amount}','gift.trafficReward':'Traffic {amount} GB','gift.expireReward':'Expiry +{days} days',
  'gift.planReward':'Plan #{id}','gift.deviceReward':'Device limit {count}','gift.rewardFallback':'See redemption result for reward details','gift.redeemed':'Redeemed: {name}','gift.notes':'Notes',

  'profile.title':'Profile','profile.desc':'Manage your password, active sessions, and security credentials.','profile.account':'Account',
  'profile.email':'Email','profile.planId':'Plan ID','profile.balance':'Balance','profile.commissionBalance':'Commission balance',
  'profile.changePassword':'Change password','profile.oldPassword':'Old password','profile.newPassword':'New password',
  'profile.confirmNewPassword':'Confirm new password','profile.savePassword':'Save password','profile.sessions':'Active sessions',
  'profile.created':'Created {time}','profile.lastUsed':'Last used {time}','profile.remove':'Remove',
  'profile.noSessions':'No active sessions','profile.security':'Security','profile.securityDesc':'Resetting security credentials invalidates old credentials.',
  'profile.quickLogin':'Generate quick-login link','profile.resetSecurity':'Reset security credentials',
  'profile.passwordUpdated':'Password updated','profile.quickCopied':'Quick-login link copied','profile.securityReset':'Security credentials reset','profile.passwordMismatch':'The new passwords do not match','profile.confirmRemove':'Remove this login session?','profile.confirmReset':'This resets security credentials and invalidates old subscription credentials. Continue?',

  'client.auto':'Auto','client.copySubscription':'Copy subscription URL','client.title':'Import subscription to client','client.desc':'Clients are filtered by device; you can also choose protocol types.','client.copy':'Copy link','client.open':'Open client','client.empty':'No matching one-click client for this device. Copy the subscription URL instead.','notFound.title':'Page not found','notFound.desc':'The page does not exist or has moved.','notFound.back':'Back to dashboard',
}

const catalogs:Record<string,Catalog>={'zh-CN':zhCN,'en-US':enUS}
const locale=ref<'zh-CN'|'en-US'>('zh-CN')

function browserDefault(){
  const lang=(navigator.language||'zh-CN').toLowerCase()
  return lang.startsWith('zh')?'zh-CN':'en-US'
}

export function initLocale(){
  const saved=localStorage.getItem('txboard_user_locale')
  locale.value=saved==='en-US'||saved==='zh-CN'?saved:browserDefault()
  document.documentElement.lang=locale.value
}

export function setLocale(code:string){
  if(code!=='zh-CN'&&code!=='en-US')return
  locale.value=code
  localStorage.setItem('txboard_user_locale',code)
  document.documentElement.lang=code
}

export function useI18n(){
  function t(key:string,params?:Record<string,string|number>){
    let value=(catalogs[locale.value]||zhCN)[key]||zhCN[key]||key
    if(params){
      for(const [name,replacement] of Object.entries(params)){
        value=value.split('{'+name+'}').join(String(replacement))
      }
    }
    return value
  }
  return {t,locale:computed(()=>locale.value),setLocale}
}
