import Extend from 'flarum/common/extenders';
import app from 'flarum/admin/app';

export default [
  new Extend.Admin()
    .setting(() => ({
      setting: 'huoxin-money-with-history.money_name',
      label: app.translator.trans('huoxin-money-with-history.admin.settings.money_name'),
      type: 'text',
    }))
    .setting(() => ({
      setting: 'huoxin-money-with-history.post_reward_amount',
      label: app.translator.trans('huoxin-money-with-history.admin.settings.post_reward_amount'),
      type: 'number',
      step: 'any',
    }))
    .setting(() => ({
      setting: 'huoxin-money-with-history.min_post_length',
      label: app.translator.trans('huoxin-money-with-history.admin.settings.min_post_length'),
      type: 'number',
    }))
    .setting(() => ({
      setting: 'huoxin-money-with-history.discussion_reward_amount',
      label: app.translator.trans('huoxin-money-with-history.admin.settings.discussion_reward_amount'),
      type: 'number',
      step: 'any',
    }))
    .setting(() => ({
      setting: 'huoxin-money-with-history.like_reward_amount',
      label: app.translator.trans('huoxin-money-with-history.admin.settings.like_reward_amount'),
      help: app.translator.trans('huoxin-money-with-history.admin.settings.help_extension_likes'),
      type: 'number',
      step: 'any',
    }))
    .setting(() => ({
      setting: 'huoxin-money-with-history.remove_money_trigger',
      label: app.translator.trans('huoxin-money-with-history.admin.settings.remove_money_trigger'),
      type: 'select',
      options: {
        '0': app.translator.trans('huoxin-money-with-history.admin.remove_money_trigger.0'),
        '1': app.translator.trans('huoxin-money-with-history.admin.remove_money_trigger.1'),
        '2': app.translator.trans('huoxin-money-with-history.admin.remove_money_trigger.2'),
      },
      default: '1',
    }))
    .setting(() => ({
      setting: 'huoxin-money-with-history.reward_self_like',
      label: app.translator.trans('huoxin-money-with-history.admin.settings.reward_self_like'),
      type: 'boolean',
    }))
    .setting(() => ({
      setting: 'huoxin-money-with-history.cascade_money_removal',
      label: app.translator.trans('huoxin-money-with-history.admin.settings.cascade_money_removal'),
      type: 'boolean',
    }))
    .setting(() => ({
      setting: 'huoxin-money-with-history.reward_private_discussion',
      label: app.translator.trans('huoxin-money-with-history.admin.settings.reward_private_discussion'),
      type: 'boolean',
    }))
    .setting(() => ({
      setting: 'huoxin-money-with-history.exclude_mentions_from_length',
      label: app.translator.trans('huoxin-money-with-history.admin.settings.exclude_mentions_from_length'),
      type: 'boolean',
    }))
    .setting(() => ({
      setting: 'huoxin-money-with-history.hide_zero_balances',
      label: app.translator.trans('huoxin-money-with-history.admin.settings.hide_zero_balances'),
      type: 'boolean',
    }))
    .permission(
      () => ({
        icon: 'fas fa-money-bill',
        label: app.translator.trans('huoxin-money-with-history.admin.permissions.edit_money_label'),
        permission: 'user.edit_money',
      }),
      'moderate'
    )
    .permission(
      () => ({
        icon: 'far fa-eye',
        label: app.translator.trans('huoxin-money-with-history.admin.permissions.disable_money_label'),
        permission: 'discussion.money.disable_money',
      }),
      'start'
    )
    .permission(
      () => ({
        icon: 'fas fa-id-card',
        label: app.translator.trans('huoxin-money-with-history.admin.permissions.query_others_history'),
        permission: 'money-history.queryOthersMoneyHistory',
        allowGuest: true,
      }),
      'view'
    ),
];
